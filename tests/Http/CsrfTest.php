<?php

use Zipavlin\Redline\Providers\Auth\AuthProvider;
use Zipavlin\Redline\Providers\Auth\OpenAuthProvider;

beforeEach(function () {
    ensureRedlineDirectories();
    $this->app->singleton(AuthProvider::class, OpenAuthProvider::class);
});

/**
 * Attach the raw (unencrypted) package cookies to the next JSON request.
 *
 * JSON test requests only carry cookies when credentials are enabled, and
 * defaultCookies would be encrypted — the package cookies are exempt from
 * encryption (EncryptCookies::except), so they must be sent raw.
 *
 * @param  array<string, string>  $cookies
 */
function withRawCookies(array $cookies)
{
    return test()->withCredentials()->withUnencryptedCookies($cookies);
}
/**
 * Register a user through the API and return the [redline_token, redline_csrf]
 * cookie values so tests can replay cookie-authenticated requests.
 *
 * @return array{0: string, 1: string}
 */
function registerAndGrabCookies(string $email = 'jane@example.com'): array
{
    $response = test()->postJson('/redline/api/auth/register', [
        'name' => 'Jane',
        'email' => $email,
        'password' => 'secret123',
        'role' => 'reporter',
    ])->assertStatus(200);

    $cookies = collect($response->baseResponse->headers->getCookies());

    $token = $cookies->first(fn ($c) => $c->getName() === 'redline_token')?->getValue();
    $csrf = $cookies->first(fn ($c) => $c->getName() === 'redline_csrf')?->getValue();

    expect($token)->toBeString()->not->toBeEmpty()
        ->and($csrf)->toBeString()->not->toBeEmpty();

    return [$token, $csrf];
}

// ---------------------------------------------------------------------------
// P1 — CSRF enforcement on cookie-authenticated mutations
// ---------------------------------------------------------------------------

it('rejects a cookie-authenticated POST /report without the X-Redline-CSRF header', function () {
    [$token, $csrf] = registerAndGrabCookies();

    withRawCookies(['redline_token' => $token, 'redline_csrf' => $csrf])
        ->postJson('/redline/api/report', makeReportData())
        ->assertStatus(419)
        ->assertJson(['message' => 'CSRF token mismatch.']);
});

it('accepts a cookie-authenticated POST /report with the matching header', function () {
    [$token, $csrf] = registerAndGrabCookies();

    withRawCookies(['redline_token' => $token, 'redline_csrf' => $csrf])
        ->postJson('/redline/api/report', makeReportData(), ['X-Redline-CSRF' => $csrf])
        ->assertStatus(201);
});

it('rejects a cookie-authenticated POST /report with a mismatched header', function () {
    [$token, $csrf] = registerAndGrabCookies();

    withRawCookies(['redline_token' => $token, 'redline_csrf' => $csrf])
        ->postJson('/redline/api/report', makeReportData(), ['X-Redline-CSRF' => 'not-the-token'])
        ->assertStatus(419)
        ->assertJson(['message' => 'CSRF token mismatch.']);
});

it('does not require CSRF for a bearer-authenticated POST /report', function () {
    $this->postJson('/redline/api/report', makeReportData(), authHeaders('reporter'))
        ->assertStatus(201);
});

it('does not require CSRF for a cookie-authenticated GET /report', function () {
    [$token, $csrf] = registerAndGrabCookies();

    withRawCookies(['redline_token' => $token, 'redline_csrf' => $csrf])
        ->getJson('/redline/api/report')
        ->assertStatus(200);
});

// ---------------------------------------------------------------------------
// P1 — Origin defense-in-depth on cookie-authenticated mutations
// ---------------------------------------------------------------------------

it('rejects a cookie-authenticated POST /report from a foreign Origin', function () {
    [$token, $csrf] = registerAndGrabCookies();

    withRawCookies(['redline_token' => $token, 'redline_csrf' => $csrf])
        ->postJson('/redline/api/report', makeReportData(), [
            'X-Redline-CSRF' => $csrf,
            'Origin' => 'https://evil.example',
        ])
        ->assertStatus(403)
        ->assertJson(['message' => 'Forbidden origin.']);
});

it('accepts a cookie-authenticated POST /report from the same-origin Origin', function () {
    [$token, $csrf] = registerAndGrabCookies();

    withRawCookies(['redline_token' => $token, 'redline_csrf' => $csrf])
        ->postJson('/redline/api/report', makeReportData(), [
            'X-Redline-CSRF' => $csrf,
            'Origin' => url('/'),
        ])
        ->assertStatus(201);
});

it('accepts a cookie-authenticated POST /report from an origin listed in allowed_origins', function () {
    config(['redline.auth.allowed_origins' => 'https://proxy.example']);

    [$token, $csrf] = registerAndGrabCookies();

    withRawCookies(['redline_token' => $token, 'redline_csrf' => $csrf])
        ->postJson('/redline/api/report', makeReportData(), [
            'X-Redline-CSRF' => $csrf,
            'Origin' => 'https://proxy.example',
        ])
        ->assertStatus(201);
});

// ---------------------------------------------------------------------------
// Logout — both cookies must be cleared
// ---------------------------------------------------------------------------

it('clears both the auth and CSRF cookies on logout', function () {
    [$token, $csrf] = registerAndGrabCookies();

    $response = withRawCookies(['redline_token' => $token, 'redline_csrf' => $csrf])
        ->deleteJson('/redline/api/auth', [], ['X-Redline-CSRF' => $csrf])
        ->assertStatus(200)
        ->assertJson(['message' => 'Logged out.']);

    $cookies = collect($response->baseResponse->headers->getCookies());

    expect((string) $cookies->first(fn ($c) => $c->getName() === 'redline_token')?->getValue())->toBe('')
        ->and((string) $cookies->first(fn ($c) => $c->getName() === 'redline_csrf')?->getValue())->toBe('');

    // The expired cookies must explicitly carry the live cookie's attributes
    // (Secure, HttpOnly, SameSite, Path) so the browser actually deletes them.
    foreach (['redline_token', 'redline_csrf'] as $name) {
        $cookie = $cookies->first(fn ($c) => $c->getName() === $name);

        expect($cookie)->not->toBeNull()
            ->and($cookie->isSecure())->toBeTrue()
            ->and($cookie->isHttpOnly())->toBeTrue()
            ->and($cookie->getSameSite())->toBe('lax')
            ->and($cookie->getPath())->toBe('/');
    }
});

// ---------------------------------------------------------------------------
// Guest mode — origin allowlist + rate limit
// ---------------------------------------------------------------------------

it('rejects a guest report from a mismatched Origin when guest_origins is configured', function () {
    config([
        'redline.auth.guest' => true,
        'redline.auth.guest_origins' => 'https://staging.example.com',
    ]);

    $this->postJson('/redline/api/report', makeReportData(), ['Origin' => 'https://evil.example'])
        ->assertStatus(403)
        ->assertJson(['message' => 'Forbidden origin.']);
});

it('rejects a guest report with no Origin when guest_origins is configured', function () {
    config([
        'redline.auth.guest' => true,
        'redline.auth.guest_origins' => 'https://staging.example.com',
    ]);

    $this->postJson('/redline/api/report', makeReportData())
        ->assertStatus(403);
});

it('accepts a guest report from an allowed Origin', function () {
    config([
        'redline.auth.guest' => true,
        'redline.auth.guest_origins' => 'https://staging.example.com',
    ]);

    $this->postJson('/redline/api/report', makeReportData(), ['Origin' => 'https://staging.example.com'])
        ->assertStatus(201);
});

it('rate limits guest report submissions per IP', function () {
    config([
        'redline.auth.guest' => true,
        'redline.auth.guest_throttle' => 2,
    ]);

    $this->postJson('/redline/api/report', makeReportData())->assertStatus(201);
    $this->postJson('/redline/api/report', makeReportData())->assertStatus(201);
    $this->postJson('/redline/api/report', makeReportData())->assertStatus(429);
});
