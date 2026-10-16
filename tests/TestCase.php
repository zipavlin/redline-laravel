<?php

namespace Zipavlin\Redline\Tests;

use Illuminate\Contracts\Http\Kernel;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Laravel\Mcp\Server\McpServiceProvider;
use Orchestra\Testbench\TestCase as Orchestra;
use Zipavlin\Redline\Enums\Feature;
use Zipavlin\Redline\Http\Middleware\ShowRedlineWidget;
use Zipavlin\Redline\Providers\Auth\AuthProvider;
use Zipavlin\Redline\Providers\Guard\GuardProvider;
use Zipavlin\Redline\Providers\Storage\StorageProvider;
use Zipavlin\Redline\RedlineServiceProvider;

class TestCase extends Orchestra
{
    /**
     * Boot-time feature list override for the current app instance. The suite
     * enables every Feature case by default; a test that must boot WITHOUT a
     * feature echoes the production REDLINE_FEATURES contract by flipping this
     * before refreshApplication() and resetting it right after.
     */
    private static ?array $bootFeatures = null;

    public static function bootWithFeatures(?array $features): void
    {
        self::$bootFeatures = $features;
    }

    /**
     * Boot-time guard provider override for the current app instance. Mirrors
     * bootWithFeatures(): flip before refreshApplication(), reset right after.
     * Read in getEnvironmentSetUp, before the app and its service providers
     * boot, so the provider sees the swapped guard class at registration time.
     */
    private static ?string $bootGuard = null;

    public static function bootWithGuard(?string $guardClass): void
    {
        self::$bootGuard = $guardClass;
    }

    /**
     * Boot-time APP_ENV override for the current app instance; mirrors
     * bootWithGuard(). Read in getEnvironmentSetUp, so the whole boot (guard
     * wiring included) runs as it would on a host whose .env says the given
     * environment - null keeps the suite's staging default.
     */
    private static ?string $bootEnv = null;

    public static function bootWithEnv(?string $env): void
    {
        self::$bootEnv = $env;
    }

    /**
     * Boot-time auth provider override; mirrors bootWithGuard().
     */
    private static ?string $bootAuth = null;

    public static function bootWithAuth(?string $authClass): void
    {
        self::$bootAuth = $authClass;
    }

    protected function setUp(): void
    {
        parent::setUp();

        // The guard requires APP_ENV=staging, which prevents Laravel's built-in
        // automatic CSRF bypass (that only fires when environment === 'testing').
        $this->withoutMiddleware(PreventRequestForgery::class);

        // Under `vendor/bin/pest`, app()->runningInConsole() is true, so the
        // service provider skips binding the pluggable providers, the model
        // contracts, and the package routes/views. Reproduce that wiring here
        // so HTTP tests exercise the real API surface.
        $this->bootRedlineForTests();
    }

    /**
     * Bind Redline's pluggable providers, model implementations, and views that
     * the service provider normally only wires outside the console.
     *
     * ROUTES: previously loaded here by hand since the provider skipped them
     * under the console. The provider now loads them (and the MCP route)
     * inside its booted() callback in the console too - the route:cache
     * lifecycle fix - so the suite exercises provider-registered routes and
     * MUST NOT load the files again (double registration would duplicate
     * every route name).
     */
    protected function bootRedlineForTests(): void
    {
        $this->app->singleton(GuardProvider::class, fn () => new (config('redline.guard.provider')));
        $this->app->singleton(AuthProvider::class, fn () => new (config('redline.auth.provider')));

        /** @var StorageProvider $storage */
        $storage = $this->app->make(StorageProvider::class);
        $storage->boot();

        $this->loadViewsFrom(dirname(__DIR__).'/resources/views', 'redline');

        // The framework's RouteServiceProvider refreshes the route name lookup
        // after loading routes; the provider's booted() callback may run after
        // that, so mirror the real bootstrap to keep fluent ->name() routes
        // (and route() / signed URLs) resolvable from the very first request.
        $this->app['router']->getRoutes()->refreshNameLookups();

        // The service provider appends InjectRedline to the `web` group only
        // outside the console; reproduce it so the middleware is exercised here.
        $this->app[Kernel::class]
            ->appendMiddlewareToGroup('web', ShowRedlineWidget::class);
    }

    protected function loadViewsFrom(string $path, string $namespace): void
    {
        $this->app['view']->addNamespace($namespace, $path);
    }

    protected function getPackageProviders($app): array
    {
        return [
            // laravel/mcp registers its package machinery through normal
            // host-app discovery; enumerate it here so the MCP wire path in
            // tests is the real one (its container resolving hook is what
            // binds the JSON-RPC mcp request to the Mcp\Request a tool is
            // handed) rather than a hand-synced stub.
            McpServiceProvider::class,

            RedlineServiceProvider::class,
        ];
    }

    public function getEnvironmentSetUp($app): void
    {
        // app()->environment() reads $app['env'], NOT config('app.env'). A
        // bootWithEnv() override simulates a host whose .env differs (used by
        // the guard-inactivity control tests); default stays staging.
        $app['env'] = self::$bootEnv ?? 'staging';
        $app['config']->set('app.env', self::$bootEnv ?? 'staging');
        $app['config']->set('app.debug', true);

        // APP_KEY is required by the web middleware group (cookie encryption).
        $app['config']->set('app.key', 'base64:'.base64_encode(str_repeat('a', 32)));

        // Use the array session driver (no file I/O needed in tests).
        $app['config']->set('session.driver', 'array');

        // Array cache so the auth rate limiter's counters reset per test (a fresh
        // app boots for each test) and don't leak throttle state across tests.
        $app['config']->set('cache.default', 'array');

        // Pre-configure the redline connection as in-memory SQLite so that
        // StorageProvider does not create a physical file during tests.
        // StorageProvider::boot() skips connection setup if already configured.
        $app['config']->set('database.connections.redline', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'foreign_key_constraints' => true,
        ]);

        // WIRING: front features are opt-in - nothing is enabled unless
        // REDLINE_FEATURES lists it. The suite's HTTP tests assume the media
        // and comment endpoints work, so the harness enables every Feature case
        // before boot (this survives the package's mergeConfigFrom, since the
        // 'features' key set here wins over the package default). Tests that
        // need a subset set config('redline.features') themselves.
        $app['config']->set('redline.features', self::$bootFeatures ?? Feature::validNames());

        // Guard provider override (see bootWithGuard) - set before providers
        // boot so the provider's route registration answers the swapped guard.
        if (self::$bootGuard !== null) {
            $app['config']->set('redline.guard.provider', self::$bootGuard);
        }

        if (self::$bootAuth !== null) {
            $app['config']->set('redline.auth.provider', self::$bootAuth);
        }
    }
}
