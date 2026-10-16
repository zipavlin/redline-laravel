<?php

use Illuminate\Http\Request;
use Zipavlin\Redline\Http\Middleware\RedlineMcpEnabled;

/**
 * Rate limiters on the MCP route. The per-IP bucket runs before auth, so an
 * unauthenticated flood must be cut off without touching the database.
 */
test('an unauthenticated flood hits the redline-mcp-ip limit with 429', function () {
    for ($attempt = 1; $attempt <= 240; $attempt++) {
        $this->post('/redline/api/mcp', initializeMcpBody())->assertUnauthorized();
    }

    // The next request from the same IP crosses the 240/min IP bucket.
    $this->post('/redline/api/mcp', initializeMcpBody())->assertStatus(429);
});

test('the per-user redline-mcp limiter applies after authentication', function () {
    ['token' => $token] = makeApiToken();

    for ($attempt = 1; $attempt <= 120; $attempt++) {
        $this->postJson('/redline/api/mcp', initializeMcpBody(), ['Authorization' => 'Bearer '.$token])
            ->assertOk();
    }

    // 121st request in the same minute: authenticated, so the per-USER bucket
    // (120/min), reached after RedlineApiAuth, is the one that trips.
    $this->postJson('/redline/api/mcp', initializeMcpBody(), ['Authorization' => 'Bearer '.$token])
        ->assertStatus(429);
});

test('the mcp feature turned off reports a plain 404 per request', function () {
    config()->set('redline.features', ['screenshots']);

    // The gate lives in RedlineMcpEnabled, in the MCP route group: it runs
    // before the rate limiters and authentication (and, being group middleware,
    // it also covers the vendor's GET/DELETE probe shapes).
    $middleware = app(RedlineMcpEnabled::class);
    $request = Request::create('/redline/api/mcp', 'POST');

    $response = $middleware->handle($request, fn () => response()->noContent());

    expect($response->getStatusCode())->toBe(404)
        ->and($response->getData(true))->toBe(['message' => 'Not found.']);
});

test('the mcp feature check does not gate other protected routes', function () {
    config()->set('redline.features', ['screenshots']);

    $user = makeUser('developer');
    $this->withHeaders(['Authorization' => 'Bearer '.makeAuthToken($user)])
        ->get('/redline/api/report')
        ->assertOk();
});
