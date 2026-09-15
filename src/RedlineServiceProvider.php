<?php

namespace Zipavlin\Redline;

use Composer\InstalledVersions;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Contracts\Container\BindingResolutionException;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Http\Request;
use Illuminate\Mail\Mailable;
use Illuminate\Support\Composer;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;
use Zipavlin\Redline\Commands\CreateUserCommand;
use Zipavlin\Redline\Commands\InstallCommand;
use Zipavlin\Redline\Commands\RemoveCommand;
use Zipavlin\Redline\Enums\Feature;
use Zipavlin\Redline\Http\Middleware\ShowRedlineWidget;
use Zipavlin\Redline\Providers\Auth\AuthProvider;
use Zipavlin\Redline\Providers\Guard\GuardProvider;
use Zipavlin\Redline\Providers\Storage\StorageProvider;

class RedlineServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Merge package config (host app's published config takes precedence).
        $this->mergeConfigFrom(__DIR__.'/../config/redline.php', 'redline');

        if (! app()->runningInConsole()) {
            $this->app->singleton(GuardProvider::class, function () {
                /** @var class-string<GuardProvider> $class */
                $class = config('redline.guard.provider');

                return new $class;
            });

            $this->app->singleton(StorageProvider::class, function () {
                /** @var class-string<StorageProvider> $class */
                $class = config('redline.storage.provider');

                return new $class;
            });

            $this->app->singleton(AuthProvider::class, function () {
                /** @var class-string<AuthProvider> $class */
                $class = config('redline.auth.provider');

                return new $class;
            });
        }
    }

    public function boot(): void
    {
        // Exempt the package cookies from Laravel's cookie encryption so the raw
        // JWT is readable by the dashboard middleware (RedlineApiAuth) and the raw
        // CSRF value by both the CSRF middleware and the widget/dashboard JS
        // (double-submit CSRF). The JWT itself is HttpOnly, so JS can never read it.
        if (class_exists(EncryptCookies::class)) {
            EncryptCookies::except([
                config('redline.auth.cookie.name', 'redline_token'),
                config('redline.auth.csrf_cookie', 'redline_csrf'),
            ]);
        }

        // Rate limiter for the public auth endpoints (login/register/verify).
        // Keyed by IP; blunts brute-forcing PINs/passwords and email enumeration.
        RateLimiter::for('redline-auth', fn (Request $request) => Limit::perMinute(
            (int) env('REDLINE_AUTH_THROTTLE', 10)
        )->by($request->ip()));

        // Rate limiter for guest report submissions (anonymous POST /report).
        // Per-minute, per-IP; limit comes from redline.auth.guest_throttle.
        RateLimiter::for('redline-guest', fn (Request $request) => Limit::perMinute(
            (int) config('redline.auth.guest_throttle', 20)
        )->by($request->ip()));

        // Rate limiter for the public bootstrap endpoint (widget/dashboard boot).
        // Generous per-IP limit because the widget fetches it on every host page load;
        // env knob: REDLINE_BOOTSTRAP_THROTTLE. Do NOT reuse redline-auth (too tight).
        RateLimiter::for('redline-bootstrap', fn (Request $request) => Limit::perMinute(
            (int) env('REDLINE_BOOTSTRAP_THROTTLE', 120)
        )->by($request->ip()));

        // Publishing is always available regardless of environment.
        $this->publishes([
            __DIR__.'/../public' => public_path('vendor/redline'),
        ], 'redline-assets');

        $this->publishes([
            __DIR__.'/../config/redline.php' => config_path('redline.php'),
        ], 'redline-config');

        // Mail templates - publish to let host apps customize the emails.
        $this->publishes([
            __DIR__.'/../resources/views/mail' => resource_path('views/vendor/redline/mail'),
        ], 'redline-mail-views');

        // Artisan commands are always registered.
        if ($this->app->runningInConsole()) {
            $this->commands([
                InstallCommand::class,
                RemoveCommand::class,
                CreateUserCommand::class,
            ]);
        } else {
            /** @var GuardProvider $guard */
            $guard = $this->app->make(GuardProvider::class);

            /** @var StorageProvider $storage */
            $storage = $this->app->make(StorageProvider::class);

            if (! $guard->shouldLoadMiddleware(request())) {
                return;
            }

            // API routes are available for local providers only when the guard
            // says the package should wire up its middleware at boot.
            if ($storage->isLocal() && $guard->shouldLoadMiddleware(request())) {
                $this->loadRoutesFrom(__DIR__.'/../routes/api.php');
            }

            // Load view files
            $this->loadViewsFrom(__DIR__.'/../resources/views', 'redline');

            // Widget injection
            if ($guard->shouldLoadMiddleware(request())) {
                $this->callAfterResolving(Kernel::class, function (Kernel $kernel): void {
                    /** @var \Illuminate\Foundation\Http\Kernel $kernel */
                    $kernel->appendMiddlewareToGroup('web', ShowRedlineWidget::class);
                });
            }

            // Dashboard is available for local providers only.
            if ($storage->isLocal() && $guard->shouldLoadMiddleware(request())) {
                $this->loadRoutesFrom(__DIR__.'/../routes/web.php');
            }

            // Bind model implementations into the IoC container.
            $storage->boot();
        }
    }

    public static function getVersion(): string
    {
        $version = InstalledVersions::getPrettyVersion('zipavlin/redline');

        return ($version && ! str_contains($version, 'dev')) ? $version : (string) Str::uuid();
    }

    public static function getViteUrl(string $location = 'widget'): ?string
    {
        // One dev server serves both widget and dashboard entries (ESM); the $location
        // param is kept for call-site compatibility but both resolve to the same port.
        return env('REDLINE_VITE_URL') ? rtrim(env('REDLINE_VITE_URL'), '/').':5173' : null;
    }

    /**
     * Whether a usable mailer is configured for the host app. Derived from env
     * (no committed config): the resolved default mailer must be a real transport
     * (not log/array/null), and the REDLINE_MAIL kill-switch must not be off.
     *
     * Email-dependent actions (verification, forgot-password, notifications) are
     * gated on this so an unconfigured mailer never breaks a request.
     */
    public static function mailConfigured(): bool
    {
        if (! filter_var(env('REDLINE_MAIL', true), FILTER_VALIDATE_BOOLEAN)) {
            return false;
        }

        $mailer = config('mail.default');

        return $mailer !== null && ! in_array($mailer, ['log', 'array', 'null'], true);
    }

    /**
     * Best-effort mail send: no-op unless a mailer is configured, and never lets a
     * mail failure bubble into the HTTP response (a misconfigured mailer is logged,
     * not fatal). Sent synchronously so it works without a queue worker - fine for
     * the low volume of a staging bug tracker.
     *
     * Returns whether the message was handed to the transport; callers that need to
     * distinguish "no mailer" from "send failed" must check mailConfigured() first.
     *
     * @param  string|array<int, string>  $to
     */
    public static function mail(string|array $to, Mailable $mailable): bool
    {
        if (empty($to) || ! self::mailConfigured()) {
            return false;
        }

        try {
            Mail::to($to)->send($mailable);

            return true;
        } catch (\Throwable $e) {
            report($e);

            return false;
        }
    }

    /**
     * Whether the Orbit package is actually usable, i.e. its Orbital trait is
     * autoloadable (FileStorageProvider's models all `use Orbital`).
     *
     * Checked via trait autoloading rather than a composer.json declaration
     * because the declaration can lie both ways: orbit may be a transitive
     * dependency (usable but undeclared) or declared but never installed
     * (unusable). It also keeps the check independent of base_path(), which
     * points at the testbench skeleton rather than the package root in tests.
     */
    public static function orbitConfigured(): bool
    {
        return trait_exists('Orbit\Concerns\Orbital');
    }

    /**
     * @throws BindingResolutionException
     */
    public static function composer(): Composer
    {
        return new Composer(app()->make(Filesystem::class), base_path());
    }

    public static function getStorageProvider(): ?string
    {
        return config('redline.storage.provider');
    }

    /**
     * @throws BindingResolutionException
     */
    public static function makeStorageProvider(): StorageProvider
    {
        return app(static::getStorageProvider());
    }

    public static function getAuthProvider(): ?string
    {
        return config('redline.auth.provider');
    }

    /**
     * @throws BindingResolutionException
     */
    public static function makeAuthProvider(): AuthProvider
    {
        return app(static::getAuthProvider());
    }

    public static function getFeatures(): array
    {
        $disabled = array_map(fn ($f) => $f->value, config('redline.disabled', []));

        return array_values(array_diff(
            array_column(Feature::cases(), 'value'),
            $disabled
        ));
    }
}
