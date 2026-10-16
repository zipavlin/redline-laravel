<?php

namespace Zipavlin\Redline;

use Composer\InstalledVersions;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Contracts\Container\BindingResolutionException;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Foundation\Exceptions\Handler;
use Illuminate\Http\Request;
use Illuminate\Mail\Mailable;
use Illuminate\Support\Composer;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;
use Laravel\Mcp\Facades\Mcp;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Zipavlin\Redline\Commands\CreateUserCommand;
use Zipavlin\Redline\Commands\EnableCommand;
use Zipavlin\Redline\Commands\InstallCommand;
use Zipavlin\Redline\Commands\McpCommand;
use Zipavlin\Redline\Commands\RemoveCommand;
use Zipavlin\Redline\Commands\UpdateCommand;
use Zipavlin\Redline\Enums\Feature;
use Zipavlin\Redline\Exceptions\ForbiddenAction;
use Zipavlin\Redline\Exceptions\ParentMismatch;
use Zipavlin\Redline\Http\Middleware\RedlineActive;
use Zipavlin\Redline\Http\Middleware\RedlineApiAuth;
use Zipavlin\Redline\Http\Middleware\RedlineMcpEnabled;
use Zipavlin\Redline\Http\Middleware\ShowRedlineWidget;
use Zipavlin\Redline\Mcp\RedlineServer;
use Zipavlin\Redline\Providers\Auth\AuthProvider;
use Zipavlin\Redline\Providers\Guard\GuardProvider;
use Zipavlin\Redline\Providers\Storage\StorageProvider;

class RedlineServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Merge package config (host app's published config takes precedence).
        $this->mergeConfigFrom(__DIR__.'/../config/redline.php', 'redline');

        // Validate the configured feature list at boot, so a mistyped value in
        // REDLINE_FEATURES fails loudly on the next request or artisan call
        // instead of only when that feature is exercised.
        Feature::enabled();

        if (! app()->runningInConsole()) {
            $this->app->singleton(GuardProvider::class, function () {
                /** @var class-string<GuardProvider> $class */
                $class = config('redline.guard.provider');

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

        // MCP: pre-auth per-IP bucket. Flood/log-noise control (auth failures
        // are noisy, not brute-forceable), 240/min, env knob
        // REDLINE_MCP_IP_THROTTLE. Deliberately NOT a password-spray defence:
        // rlm_ tokens are high-entropy random bytes.
        RateLimiter::for('redline-mcp-ip', fn (Request $request) => Limit::perMinute(
            (int) env('REDLINE_MCP_IP_THROTTLE', 240)
        )->by((string) $request->ip()));

        // MCP: post-auth per-user bucket. Registered unconditionally but placed
        // INSIDE RedlineApiAuth in the middleware order, so the key falls back
        // to IP only when no user was authenticated - the 401 path exits before
        // this limiter is ever reached.
        RateLimiter::for('redline-mcp', fn (Request $request) => Limit::perMinute(
            (int) env('REDLINE_MCP_THROTTLE', 120)
        )->by((string) ($request->user()?->id ?? $request->ip())));

        // The Actions layer's domain exceptions render for the whole redline
        // API group in ONE place, byte-identical to the `abort()` envelopes the
        // controllers used to throw themselves: ForbiddenAction (the 403
        // family - gate, disabled feature, invalid transition) keeps its exact
        // message; ParentMismatch keeps the bare 404. The registered callback
        // re-renders the equivalent Symfony exception through the default
        // handler, so every response field (including debug extras) matches
        // what abort() produced. MCP keeps its own error vocabulary: the
        // endpoint is excluded from this renderer (its tools map the same
        // exceptions to tool errors in Mcp\Concerns\CanMapActionErrors).
        /** @var Handler $exceptionHandler */
        $exceptionHandler = $this->app->make(ExceptionHandler::class);

        // A host may bind a handler that has neither helper; the package's
        // rendering and log filtering are best-effort additions, never a boot
        // requirement.
        if (! method_exists($exceptionHandler, 'renderable')) {
            return;
        }

        // A 403/404 the package raises in normal operation is not an incident:
        // without this every refusal would also log a stack trace through the
        // host's exception log (ForbiddenAction covers the whole 403 family -
        // the gate, a disabled feature, an invalid transition).
        $exceptionHandler->dontReport([
            ForbiddenAction::class,
            ParentMismatch::class,
        ]);

        $exceptionHandler->renderable(function (ForbiddenAction|ParentMismatch $exception, Request $request) use ($exceptionHandler) {
            if (! $request->is(config('redline.path', 'redline').'/api/*')
                || $request->is(config('redline.path', 'redline').'/api/mcp*')) {
                return null;
            }

            // The precise exception shape the controller's `abort()` used to
            // throw: abort(403, message) => HttpException, abort(404) =>
            // NotFoundHttpException with the same (empty) message.
            $wrapped = $exception instanceof ParentMismatch
                ? new NotFoundHttpException
                : new HttpException(403, $exception->getMessage());

            return $exceptionHandler->render($request, $wrapped);
        });

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
                UpdateCommand::class,
                EnableCommand::class,
                RemoveCommand::class,
                CreateUserCommand::class,
                McpCommand::class,
            ]);
        } else {
            /** @var GuardProvider $guard */
            $guard = $this->app->make(GuardProvider::class);

            if (! $guard->shouldLoadMiddleware(request())) {
                return;
            }

            /** @var StorageProvider $storage */
            $storage = $this->app->make(StorageProvider::class);

            // API routes and the dashboard shell are available once the guard
            // says the package should wire up its middleware at boot.
            $this->loadRoutesFrom(__DIR__.'/../routes/api.php');
            $this->loadRoutesFrom(__DIR__.'/../routes/web.php');

            // Load view files
            $this->loadViewsFrom(__DIR__.'/../resources/views', 'redline');

            // Widget injection
            $this->callAfterResolving(Kernel::class, function (Kernel $kernel): void {
                /** @var \Illuminate\Foundation\Http\Kernel $kernel */
                $kernel->appendMiddlewareToGroup('web', ShowRedlineWidget::class);
            });

            // Bind model implementations into the IoC container.
            $storage->boot();
        }

        // Route registration that survives a route cache - the package's HTTP
        // surface used to vanish entirely under `php artisan route:cache`.
        $this->app->booted(function (): void {
            $this->registerRoutesAcrossCaching();
        });
    }

    /**
     * Register the MCP route and - in the console only - load the package route
     * files, so `php artisan route:cache` compiles a cache that HOLDS the
     * package's HTTP surface.
     *
     * Route files load in the console unconditionally (route:cache, artisan,
     * tests): no guard decision is made at compile time, because one cannot be
     * made - request-state guards (an IP guard needs the request IP;
     * LaravelGuardProvider needs a started session) are unanswerable at
     * route:cache time, and a compiled cache crosses environments (built on
     * staging, served on production), so a compile-time answer would either
     * miscompile or leave the public surface bare on the serving host.
     * Instead the guard is enforced PER REQUEST - RedlineActive covers the
     * api + web groups (404 api / 403 web) and the `mcp` feature is enforced
     * per request by RedlineMcpEnabled - which is exactly what makes a cache
     * built in one environment safe to serve in another. The MCP route is
     * guarded per request like every other redline surface: the registration
     * runs INSIDE a Route::middleware([RedlineActive, RedlineMcpEnabled])
     * ::group(), so both gates are prepended to EVERY route laravel/mcp creates
     * for the URI - the POST server route (ahead of laravel/mcp's own
     * ReorderJsonAccept/ValidateMcpHeaders/AddWwwAuthenticateHeader and of our
     * rate limiter + redline auth) and the vendor's unnamed GET/DELETE 405
     * shapes, which carry no other middleware at all. The group middleware
     * stays FIRST on all three, so a guard-inactive host answers 404 before
     * vendor header validation (a malformed-header POST would otherwise be a
     * 400), before a method probe (a GET would otherwise be a 405), and before
     * any rate limiting or authentication (zero traces: not even a limiter
     * side effect); a host whose `mcp` feature was turned off after a cache was
     * compiled gets the same bare 404 on all three shapes instead of a stale
     * server or a vendor 405.
     *
     * That per-request wrapping is safe under every built-in guard: under
     * EnvironmentGuardProvider (the default), IpGuardProvider and
     * DebugGuardProvider the predicate reads no session, and under
     * LaravelGuardProvider shouldServeRoutes() is always true. The feature is
     * still feature-gated and bearer-authenticated (`rlm_` tokens only), so
     * the route keeps round-tripping a cache whenever the feature is on.
     *
     *  - console (route:cache, artisan, tests): route files load here;
     *  - HTTP runtime without a cache: routes load in the guard branch in
     *    boot() (loading them again here would duplicate every route name),
     *    while the MCP route registers here (feature-gated);
     *  - HTTP runtime with a cache: nothing re-registers (the cache already
     *    holds it); enforcement that cannot be baked into a cache stays
     *    per-request (RedlineActive, RedlineMcpEnabled).
     */
    private function registerRoutesAcrossCaching(): void
    {
        if ($this->app->routesAreCached() && ! $this->app->runningInConsole()) {
            return;
        }

        if ($this->app->runningInConsole()) {
            $this->loadRoutesFrom(__DIR__.'/../routes/api.php');
            $this->loadRoutesFrom(__DIR__.'/../routes/web.php');
        }

        if (! in_array(Feature::MCP, Feature::enabled(), true)) {
            return;
        }

        // laravel/mcp attaches ReorderJsonAccept / ValidateMcpHeaders /
        // AddWwwAuthenticateHeader itself and ->middleware() only APPENDS to
        // that list - so RedlineActive inside ->middleware() would NOT be
        // the outermost middleware, and the vendor GET/DELETE 405 routes
        // (Registrar::web() registers them on the same URI with NO
        // middleware at all) would answer 405 on a guard-inactive host. The
        // group wrap fixes both: Laravel prepends group middleware onto
        // every route created inside, so the guard leads all three shapes.
        // Route-level ->middleware() only orders what follows: IP bucket
        // before auth, user bucket after (its key needs the resolved user).
        Route::middleware([RedlineActive::class, RedlineMcpEnabled::class])->group(function (): void {
            Mcp::web(config('redline.path', 'redline').'/api/mcp', RedlineServer::class)
                ->name('redline.mcp')
                ->middleware([
                    'throttle:redline-mcp-ip',
                    RedlineApiAuth::class,
                    'throttle:redline-mcp',
                ]);
        });
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
     * @throws BindingResolutionException
     */
    public static function composer(): Composer
    {
        return new Composer(app()->make(Filesystem::class), base_path());
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
        return array_map(fn (Feature $feature) => $feature->value, Feature::enabled());
    }
}
