<?php

namespace Zipavlin\Redline\Tests\Mcp;

use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Http\Request;
use Illuminate\Session\Middleware\StartSession;
use Zipavlin\Redline\Enums\Feature;
use Zipavlin\Redline\Http\Middleware\RedlineApiAuth;
use Zipavlin\Redline\Http\Middleware\RedlineCsrf;
use Zipavlin\Redline\Providers\Auth\LaravelAuthProvider;
use Zipavlin\Redline\Providers\Guard\LaravelGuardProvider;
use Zipavlin\Redline\Tests\TestCase;

/**
 * Route lifecycle for the MCP mount: feature gate at boot, LaravelAuthProvider
 * wiring, middleware composition, and the route:cache round trip that used to
 * silently drop the package's whole HTTP surface.
 */
it('the named mcp route mounts with pinned middleware', function () {
    $route = app('router')->getRoutes()->getByName('redline.mcp');

    expect($route)->not->toBeNull();
    expect($route->uri())->toBe('redline/api/mcp');

    $middleware = $route->middleware();

    expect(array_values(array_intersect($middleware, [
        'throttle:redline-mcp-ip',
        RedlineApiAuth::class,
        'throttle:redline-mcp',
    ])))->toBe([
        'throttle:redline-mcp-ip',
        RedlineApiAuth::class,
        'throttle:redline-mcp',
    ]);
    expect($middleware)->not->toContain(RedlineCsrf::class, StartSession::class, EncryptCookies::class);
});

it('the test harness exercises provider registered routes', function () {
    // TestCase no longer requires the route files; every Redline route
    // came from the provider's booted() callback.
    expect($this->app->routesAreCached())->toBeFalse();
    expect(app('router')->getRoutes()->getByName('redline.auth.login'))->not->toBeNull();
    expect(app('router')->getRoutes()->getByName('redline.mcp'))->not->toBeNull();
});

it('route resolves under laravel auth provider wiring', function () {
    // Re-boot with the Laravel pair configured (mirrors how redline:install
    // writes both classes into .env): the routes then come from a boot whose
    // auth/guard providers are the session-exchange pair, not the defaults.
    TestCase::bootWithGuard(LaravelGuardProvider::class);
    TestCase::bootWithAuth(LaravelAuthProvider::class);

    try {
        $this->refreshApplication();

        expect(app('router')->getRoutes()->getByName('redline.mcp'))->not->toBeNull();
        expect(app('router')->getRoutes()->getByName('redline.bootstrap'))->not->toBeNull();
    } finally {
        TestCase::bootWithGuard(null);
        TestCase::bootWithAuth(null);

        $this->refreshApplication();
    }
});

it('route cache round trip keeps the package routes', function () {
    foreach (['redline.bootstrap', 'redline.dashboard', 'redline.mcp'] as $name) {
        // route [$name] missing
        expect(app('router')->getRoutes()->getByName($name))->not->toBeNull();
    }

    // Serialize + compile byte-for-byte the way RouteCacheCommand's
    // buildRouteCacheFile() does, write the true cache file, and load it
    // back exactly as a cached boot does (require -> setCompiledRoutes).
    $routes = $this->app['router']->getRoutes();
    $routes->refreshNameLookups();
    $routes->refreshActionLookups();

    foreach ($routes->getRoutes() as $route) {
        $route->prepareForSerialization();
    }

    $cachePath = $this->app->getCachedRoutesPath();

    try {
        file_put_contents($cachePath, "<?php\n\napp('router')->setCompiledRoutes(\n".var_export($routes->compile(), true)."\n);\n");

        require $cachePath; // the cached-boot mechanism

        foreach (['redline.bootstrap', 'redline.dashboard', 'redline.mcp'] as $name) {
            // route [$name] lost by the cache
            expect(app('router')->getRoutes()->getByName($name))->not->toBeNull();
        }

        $match = app('router')->getRoutes()->match(
            Request::create('/redline/api/mcp', 'POST')
        );

        expect($match->getName())->toBe('redline.mcp');
    } finally {
        @unlink($cachePath);
    }
});

it('mcp route is absent when the feature is disabled', function () {
    // Booted with no `mcp` feature listed - the MCP route is never mounted.
    // The production REDLINE_FEATURES contract is echoed through the harness's
    // boot override, since PHPUnit attributes cannot ride a Pest closure.
    TestCase::bootWithFeatures(array_values(array_diff(Feature::validNames(), ['mcp'])));

    try {
        $this->refreshApplication();

        expect(app('router')->getRoutes()->getByName('redline.mcp'))->toBeNull();
        // The package's own HTTP surface stays (other features still gate it).
        expect(app('router')->getRoutes()->getByName('redline.bootstrap'))->not->toBeNull();
    } finally {
        TestCase::bootWithFeatures(null);

        // Restore a fully-featured boot for teardown and later tests.
        $this->refreshApplication();
    }
});
