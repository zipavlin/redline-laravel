<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Zipavlin\Redline\Enums\Feature;
use Zipavlin\Redline\Http\Middleware\RedlineActive;
use Zipavlin\Redline\Http\Middleware\RedlineApiAuth;
use Zipavlin\Redline\Http\Middleware\RedlineMcpEnabled;
use Zipavlin\Redline\Providers\Guard\EnvironmentGuardProvider;
use Zipavlin\Redline\Providers\Guard\GuardProvider;
use Zipavlin\Redline\Tests\TestCase;

/**
 * The guard denies PER REQUEST (RedlineActive middleware), never at compile
 * time: route files load into the console whatever the guard says, so a route
 * cache is environment-portable, and an inactive guard must 404 the package's
 * public API surface (403 on the dashboard shell) on the request being served.
 *
 * The MCP registration runs inside a route GROUP that starts with the guard, so
 * RedlineActive outranks EVERY shape laravel/mcp creates for the URI - the POST
 * server route (ahead of laravel/mcp's own header middleware and of our rate
 * limiters and auth) and the vendor's unnamed GET/DELETE probes, whose closures
 * answer 405 themselves and carry no other middleware. Without the group those
 * two shapes would answer 405 and a malformed-header POST would answer 400 on a
 * guard-inactive host. RedlineMcpEnabled rides the same group, so the `mcp`
 * feature's per-request 404 covers all three shapes too, cache or no cache.
 */

/**
 * A guard that never activates - the shape a production host's
 * EnvironmentGuardProvider degrades to (APP_ENV=production).
 */
class InertGuardProvider extends GuardProvider
{
    public function isActive(Request $request): bool
    {
        return false;
    }
}

/**
 * Serialize + compile byte-for-byte the way RouteCacheCommand's
 * buildRouteCacheFile() does, write the true cache file, and load it back
 * exactly as a cached boot does (require -> setCompiledRoutes). The caller
 * owns the cleanup (@unlink the cache path in a finally) - it is the real
 * cache path and would otherwise poison every later boot in the process.
 */
function loadCompiledRouteCacheInTest(): void
{
    $routes = app('router')->getRoutes();
    $routes->refreshNameLookups();
    $routes->refreshActionLookups();

    foreach ($routes->getRoutes() as $route) {
        $route->prepareForSerialization();
    }

    file_put_contents(
        app()->getCachedRoutesPath(),
        "<?php\n\napp('router')->setCompiledRoutes(\n".var_export($routes->compile(), true)."\n);\n",
    );

    require app()->getCachedRoutesPath(); // the cached-boot mechanism
}

/**
 * Post a VALID MCP initialize request carrying a valid rlm_ bearer - a shape
 * that was answered 200 before RedlineActive wrapped the route - and prove
 * the 404 is the guard's, emitted ahead of authentication and both rate
 * limiters: no limiter side effect is ever made (spy sees no
 * tooManyAttempts/hit), and RedlineApiAuth never runs (an invalid rlm_
 * signature, which auth would have answered 401, stays a bare 404).
 */
function expectMcpGuardInactivity(TestCase $test): void
{
    RateLimiter::spy();

    ['token' => $token] = makeApiToken();

    $test->postJson('/redline/api/mcp', initializeMcpBody(), [
        'Authorization' => 'Bearer '.$token,
    ])->assertNotFound()
        ->assertHeaderMissing('WWW-Authenticate');

    $test->postJson('/redline/api/mcp', initializeMcpBody(), [
        'Authorization' => 'Bearer rlm_'.str_repeat('f', 40),
    ])->assertNotFound();

    // The two shapes the guard group is there for: without it laravel/mcp's own
    // header validation answers this malformed POST with 400 and the vendor's
    // unnamed GET/DELETE probes answer 405 - neither reaches a bare 404.
    $test->postJson('/redline/api/mcp', initializeMcpBody(), [
        'MCP-Protocol-Version' => '1999-01-01',
    ])->assertNotFound();

    $test->get('/redline/api/mcp')->assertNotFound();

    $test->delete('/redline/api/mcp')->assertNotFound();

    RateLimiter::shouldNotHaveReceived('hit');
    RateLimiter::shouldNotHaveReceived('tooManyAttempts');
}

it('the guard and the feature gate lead every shape laravel/mcp registers for the mcp uri', function () {
    $uri = 'redline/api/mcp';

    $shapes = collect(app('router')->getRoutes()->getRoutes())
        ->filter(fn ($route) => $route->uri() === $uri)
        ->values();

    // The POST server route plus the vendor's two unnamed probe shapes.
    expect($shapes)->toHaveCount(3);

    foreach ($shapes as $route) {
        expect($route->middleware()[0])->toBe(RedlineActive::class)
            ->and($route->middleware()[1])->toBe(RedlineMcpEnabled::class);
    }

    $post = $shapes->first(fn ($route) => in_array('POST', $route->methods(), true));

    expect($post->getName())->toBe('redline.mcp');

    // On the server route the pinned order after the two gates is: IP bucket,
    // then authentication, then the user-keyed bucket (its key needs the
    // resolved user).
    $chain = $post->middleware();
    $ip = array_search('throttle:redline-mcp-ip', $chain, true);
    $auth = array_search(RedlineApiAuth::class, $chain, true);
    $user = array_search('throttle:redline-mcp', $chain, true);

    expect($ip)->toBeLessThan($auth)->and($auth)->toBeLessThan($user);
});

it('an active guard exposes the vendor shapes the guard group has to outrank', function () {
    // Documenting why the group wrap exists: with an ACTIVE guard (and the
    // feature on) laravel/mcp answers the malformed POST with 400 and the
    // GET/DELETE probes with 405. The inactive-guard cases below pin that the
    // guard turns every one of those into the same bare 404.
    $this->postJson('/redline/api/mcp', initializeMcpBody(), [
        'MCP-Protocol-Version' => '1999-01-01',
    ])->assertStatus(400);

    $this->get('/redline/api/mcp')->assertStatus(405);
    $this->delete('/redline/api/mcp')->assertStatus(405);
});

it('a feature turned off after a cache 404s every mcp shape, never a vendor 400/405 or a stale server', function () {
    $cachePath = app()->getCachedRoutesPath();

    try {
        // Compiled while `mcp` was ON - the cache a deployed staging host has.
        loadCompiledRouteCacheInTest();

        // The serving host has the feature off (env changed after the cache).
        TestCase::bootWithFeatures(array_values(array_diff(Feature::validNames(), ['mcp'])));

        $this->refreshApplication();
        $this->bootRedlineForTests();

        require app()->getCachedRoutesPath(); // the cached-boot mechanism

        expect(app('router')->getRoutes()->getByName('redline.mcp'))->not->toBeNull();

        $this->postJson('/redline/api/mcp', initializeMcpBody())->assertNotFound();
        $this->postJson('/redline/api/mcp', initializeMcpBody(), [
            'MCP-Protocol-Version' => '1999-01-01',
        ])->assertNotFound();
        $this->get('/redline/api/mcp')->assertNotFound();
        $this->delete('/redline/api/mcp')->assertNotFound();
    } finally {
        @unlink($cachePath);

        TestCase::bootWithFeatures(null);

        $this->refreshApplication();
        $this->bootRedlineForTests();
    }
});

it('an inactive guard denies the compiled public surface per request', function () {
    // The cache path is deterministic - know it before anything can throw, so
    // the finally can always clean up (a stale cache file poisons later boots).
    $cachePath = app()->getCachedRoutesPath();

    TestCase::bootWithGuard(InertGuardProvider::class);

    try {
        $this->refreshApplication();
        $this->bootRedlineForTests();

        // A cache is environment-portable: it carries the surface whatever the
        // guard said at compile time. Denial is per request (RedlineActive).
        loadCompiledRouteCacheInTest();

        foreach (['redline.bootstrap', 'redline.auth.login', 'redline.mcp'] as $name) {
            expect(app('router')->getRoutes()->getByName($name))->not->toBeNull();
        }

        // Public API surface: 404 (the routes are simply absent for this host).
        $this->get('/redline/api/bootstrap')->assertNotFound();
        $this->post('/redline/api/auth/register', ['name' => 'A', 'email' => 'a@example.com', 'password' => 'secret123'])->assertNotFound();

        // The mcp route, cache or no cache: guarded per request - same 404.
        expectMcpGuardInactivity($this);

        // Dashboard shell keeps its documented per-request denial: 403.
        $this->get('/redline')->assertForbidden();
    } finally {
        @unlink($cachePath);

        TestCase::bootWithGuard(null);

        $this->refreshApplication();
        $this->bootRedlineForTests();
    }
});

it('an inactive guard denies the public surface the same way without a cache', function () {
    TestCase::bootWithGuard(InertGuardProvider::class);

    try {
        $this->refreshApplication();
        $this->bootRedlineForTests();

        expect($this->app->routesAreCached())->toBeFalse();

        $this->get('/redline/api/bootstrap')->assertNotFound();
        $this->post('/redline/api/auth/register', ['name' => 'A', 'email' => 'a@example.com', 'password' => 'secret123'])->assertNotFound();
        $this->get('/redline')->assertForbidden();
    } finally {
        TestCase::bootWithGuard(null);

        $this->refreshApplication();
        $this->bootRedlineForTests();
    }
});

it('an inactive guard answers the mcp route with a bare 404 without a cache', function () {
    TestCase::bootWithGuard(InertGuardProvider::class);

    try {
        $this->refreshApplication();
        $this->bootRedlineForTests();

        expect($this->app->routesAreCached())->toBeFalse();

        expectMcpGuardInactivity($this);
    } finally {
        TestCase::bootWithGuard(null);

        $this->refreshApplication();
        $this->bootRedlineForTests();
    }
});

it('an active guard keeps the public surface reachable', function () {
    // The control case: the default staging-active guard as the configured
    // provider - the same endpoints serve normally.
    TestCase::bootWithGuard(EnvironmentGuardProvider::class);

    try {
        $this->refreshApplication();
        $this->bootRedlineForTests();

        $this->get('/redline/api/bootstrap')->assertOk();
        $this->get('/redline')->assertOk();
    } finally {
        TestCase::bootWithGuard(null);

        $this->refreshApplication();
        $this->bootRedlineForTests();
    }
});

it('an active guard with the package path excluded keeps the surface served and only skips widget injection', function () {
    // The documented split, pinned: REDLINE_GUARD_EXCLUDE hides the WIDGET on
    // matching paths - it never disables the package's own api + web surface
    // (the dashboard especially must stay served on an excluded path). The
    // surface predicate is GuardProvider::shouldServeRoutes(), which does not
    // fold in the exclusion list shouldLoadMiddleware() uses for boot wiring.
    config(['redline.guard.exclude' => 'redline,host/area']);

    // Host pages on excluded and non-excluded paths, registered after boot
    // the way InjectRedlineTest does.
    $this->app['router']->get('/host/area', fn () => response(
        '<html><body>Area</body></html>',
        200,
        ['Content-Type' => 'text/html; charset=utf-8'],
    ))->middleware('web');
    $this->app['router']->get('/host/other', fn () => response(
        '<html><body>Other</body></html>',
        200,
        ['Content-Type' => 'text/html; charset=utf-8'],
    ))->middleware('web');

    // Route side: exclusion never disables the surface (200s, not 404/403).
    $this->get('/redline/api/bootstrap')->assertOk();
    $this->get('/redline')->assertOk();

    // Widget side: the excluded path is widget-free...
    expect($this->get('/host/area')->assertOk()->getContent())
        ->not->toContain('redline-widget');

    // ...and exclusion is path-scoped, so the other host page still carries it.
    expect($this->get('/host/other')->assertOk()->getContent())
        ->toContain('redline-widget');
});
