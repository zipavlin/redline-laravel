<?php

use Illuminate\Auth\GenericUser;
use Zipavlin\Redline\Providers\Auth\LaravelAuthProvider;
use Zipavlin\Redline\Providers\Guard\LaravelGuardProvider;
use Zipavlin\Redline\Tests\TestCase;

/**
 * The API surface under LaravelGuardProvider.
 *
 * RedlineActive sits on the OUTER api group - before EncryptCookies and
 * StartSession - so the session cannot have started yet when it runs. Its
 * predicate therefore consults `shouldServeRoutes()` - the per-request route
 * declaration that LaravelGuardProvider overrides to always serve - and never
 * `isActive()`: that is a session lookup, so calling it from `RedlineActive` dead-ends the whole API
 * surface with a 404 - a logged-in host user included, and with it the
 * `POST /auth/login` session exchange that LaravelAuthProvider depends on.
 *
 * These tests boot the documented Laravel pair (guard + auth) and assert the
 * observable request outcomes on the API surface.
 */

/** Re-boot the app with the Laravel guard + auth pair, the way
 *  redline:install writes both classes into .env. */
function bootLaravelGuardPair(): void
{
    TestCase::bootWithGuard(LaravelGuardProvider::class);
    TestCase::bootWithAuth(LaravelAuthProvider::class);

    test()->refreshApplication();
    test()->bootRedlineForTests();
}

/** Restore the suite's default boot exactly like RouteGuardCacheTest - a test
 *  must never leave the boot overrides set for later tests in the same run. */
function bootBackAfterLaravelGuardPair(): void
{
    TestCase::bootWithGuard(null);
    TestCase::bootWithAuth(null);

    test()->refreshApplication();
    test()->bootRedlineForTests();
}

/** Act as a host user on the configured (session) guard, mirroring how a host
 *  application's own auth middleware would have preset the guard. */
function actingAsHostApplicationUser(string $email = 'host@example.com'): GenericUser
{
    $hostUser = new GenericUser(['id' => 1, 'name' => 'Host User', 'email' => $email]);

    test()->actingAs($hostUser);

    return $hostUser;
}

it('serves the session exchange to a logged-in host user', function () {
    bootLaravelGuardPair();

    try {
        actingAsHostApplicationUser('exchange@example.com');

        $this->postJson('/redline/api/auth/login', [])
            ->assertOk()
            ->assertJsonPath('user.email', 'exchange@example.com')
            ->assertJsonPath('user.role', 'reporter');
    } finally {
        bootBackAfterLaravelGuardPair();
    }
});

it('denies a session login through the auth envelope when no host session exists, never through the guard', function () {
    bootLaravelGuardPair();

    try {
        // No acting host user: at this point in the pipeline the guard cannot
        // claim activation. The request must still reach the controller, whose
        // provider answers 401 - a guard-middleware 404 would hide the surface.
        $this->postJson('/redline/api/auth/login', [])
            ->assertUnauthorized()
            ->assertJsonPath('errors.0', 'Not authenticated with the host application.');

        // A protected endpoint without redline credentials is answered by
        // RedlineApiAuth (401), not by RedlineActive (404).
        $this->getJson('/redline/api/auth/me')->assertUnauthorized();
    } finally {
        bootBackAfterLaravelGuardPair();
    }
});

it('exchanges the host session and then serves the protected surface to the minted token', function () {
    bootLaravelGuardPair();

    try {
        actingAsHostApplicationUser('roundtrip@example.com');

        $token = collect($this->postJson('/redline/api/auth/login', [])
            ->assertOk()
            ->baseResponse->headers->getCookies())
            ->first(fn ($cookie) => $cookie->getName() === 'redline_token')
            ->getValue();

        expect($token)->toBeString()->not->toBeEmpty();

        $this->getJson('/redline/api/auth/me', ['Authorization' => 'Bearer '.$token])
            ->assertOk()
            ->assertJsonPath('user.email', 'roundtrip@example.com');
    } finally {
        bootBackAfterLaravelGuardPair();
    }
});

it('serves the MCP endpoint under LaravelGuardProvider with mcp enabled and no host session - auth 401/200, never the guard 404', function () {
    bootLaravelGuardPair();

    try {
        // No acting host user and no bearer: the guard still serves the route
        // (shouldServeRoutes() is a no-op for this provider) and the answer
        // comes from RedlineApiAuth - a 404 here would mark the surface
        // unreachable and redline:mcp unusable on a Laravel-guard host.
        $this->postJson('/redline/api/mcp', initializeMcpBody())
            ->assertUnauthorized()
            ->assertJson(['message' => 'Unauthenticated.'])
            ->assertHeader('WWW-Authenticate');

        // A freshly issued rlm_ bearer reaches the MCP server itself.
        ['token' => $token] = makeApiToken();

        $this->postJson('/redline/api/mcp', initializeMcpBody(), [
            'Authorization' => 'Bearer '.$token,
        ])->assertOk()
            ->assertJsonPath('jsonrpc', '2.0')
            ->assertJsonPath('result.serverInfo.name', 'redline');
    } finally {
        bootBackAfterLaravelGuardPair();
    }
});
