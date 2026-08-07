<?php

namespace Zipavlin\Redline\Tests;

use Illuminate\Contracts\Http\Kernel;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Orchestra\Testbench\TestCase as Orchestra;
use Zipavlin\Redline\Http\Middleware\ShowRedlineWidget;
use Zipavlin\Redline\Providers\Auth\AuthProvider;
use Zipavlin\Redline\Providers\Guard\GuardProvider;
use Zipavlin\Redline\Providers\Storage\StorageProvider;
use Zipavlin\Redline\RedlineServiceProvider;

class TestCase extends Orchestra
{
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
     * Bind Redline's pluggable providers, model implementations, and routes/views
     * that the service provider normally only wires outside the console.
     */
    protected function bootRedlineForTests(): void
    {
        $this->app->singleton(GuardProvider::class, fn () => new (config('redline.guard.provider')));
        $this->app->singleton(StorageProvider::class, fn () => new (config('redline.storage.provider')));
        $this->app->singleton(AuthProvider::class, fn () => new (config('redline.auth.provider')));

        /** @var StorageProvider $storage */
        $storage = $this->app->make(StorageProvider::class);
        $storage->boot();

        if ($storage->isLocal()) {
            $this->loadRoutesFrom(dirname(__DIR__).'/routes/web.php');
            $this->loadRoutesFrom(dirname(__DIR__).'/routes/api.php');
            $this->loadViewsFrom(dirname(__DIR__).'/resources/views', 'redline');
        }

        // The service provider appends InjectRedline to the `web` group only
        // outside the console; reproduce it so the middleware is exercised here.
        $this->app[Kernel::class]
            ->appendMiddlewareToGroup('web', ShowRedlineWidget::class);
    }

    protected function loadRoutesFrom(string $path): void
    {
        require $path;
    }

    protected function loadViewsFrom(string $path, string $namespace): void
    {
        $this->app['view']->addNamespace($namespace, $path);
    }

    protected function getPackageProviders($app): array
    {
        return [
            RedlineServiceProvider::class,
        ];
    }

    public function getEnvironmentSetUp($app): void
    {
        // app()->environment() reads $app['env'], NOT config('app.env').
        $app['env'] = 'staging';
        $app['config']->set('app.env', 'staging');
        $app['config']->set('app.debug', true);

        // APP_KEY is required by the web middleware group (cookie encryption).
        $app['config']->set('app.key', 'base64:'.base64_encode(str_repeat('a', 32)));

        // Use the array session driver (no file I/O needed in tests).
        $app['config']->set('session.driver', 'array');

        // Array cache so the auth rate limiter's counters reset per test (a fresh
        // app boots for each test) and don't leak throttle state across tests.
        $app['config']->set('cache.default', 'array');

        // Pre-configure the redline connection as in-memory SQLite so that
        // DatabaseStorageProvider does not create a physical file during tests.
        // DatabaseStorageProvider.configureConnection() skips if already set.
        $app['config']->set('database.connections.redline', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'foreign_key_constraints' => true,
        ]);
    }
}
