<?php

use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Http\Request;
use Zipavlin\Redline\Exceptions\Unauthenticated;
use Zipavlin\Redline\Tests\TestCase;

/**
 * Contract v3 surface pin: no unauthenticated response on the redline
 * browser-facing API surface may ever carry a WWW-Authenticate header.
 *
 * Why this exists: Chrome pops the NATIVE Basic-Auth dialog whenever a
 * credentialed fetch receives HTTP 401 - with or without a challenge header -
 * so the API surface must not answer 401 at all (contract v3: 403 with the
 * `error: unauthenticated` marker). A host behind server-side Basic Auth is
 * broken by any slip back to 401, and header-stripping recipes can never fix
 * Chrome when the popup is driven by the status itself. The MCP route is the
 * deliberate exception (MCP clients expect 401 + the Bearer challenge) and is
 * pinned here purely as contrast.
 */
it('does not challenge on an anonymous auth/me probe (contract v3: 200 anonymous)', function () {
    $this->getJson('/redline/api/auth/me')
        ->assertOk()
        ->assertJson(['user' => null])
        ->assertHeaderMissing('WWW-Authenticate');
});

it('does not challenge on auth/me with presented-but-invalid credentials', function () {
    $this->getJson('/redline/api/auth/me', ['Authorization' => 'Bearer invalid.token.value'])
        ->assertStatus(403)
        ->assertJson(['error' => 'unauthenticated'])
        ->assertHeaderMissing('WWW-Authenticate');
});

it('does not challenge on a protected resource without credentials', function () {
    $this->getJson('/redline/api/report')
        ->assertStatus(403)
        ->assertJson(['error' => 'unauthenticated'])
        ->assertHeaderMissing('WWW-Authenticate');
});

it('does not challenge on a signed-out refresh request', function () {
    $this->getJson('/redline/api/auth/refresh')
        ->assertStatus(403)
        ->assertJson(['error' => 'unauthenticated'])
        ->assertHeaderMissing('WWW-Authenticate');
});

it('does not challenge on a rejected login attempt', function () {
    $this->postJson('/redline/api/auth/login', ['email' => 'nobody@example.com', 'password' => 'wrong-password'])
        ->assertStatus(403)
        ->assertJson(['error' => 'unauthenticated'])
        ->assertHeaderMissing('WWW-Authenticate');
});

it('does not challenge on a guest report attempt when guest mode is off', function () {
    $this->postJson('/redline/api/report', makeReportData())
        ->assertStatus(403)
        ->assertJson(['error' => 'unauthenticated'])
        ->assertHeaderMissing('WWW-Authenticate');
});

// ---------------------------------------------------------------------------
// Negative pins: denials that are NOT authentication denials carry no marker
// ---------------------------------------------------------------------------

it('does not mark a plain ForbiddenAction 403 with the error field', function () {
    $user = makeUser('reporter');

    $this->postJson('/redline/api/user', [
        'email' => 'created@redline.local', 'name' => 'Created', 'password' => 'secret123',
    ], authHeaders('reporter'))
        ->assertStatus(403)
        ->assertJsonMissingPath('error')
        ->assertHeaderMissing('WWW-Authenticate');
});

it('does not mark the guest Forbidden origin. 403 with the error field', function () {
    config(['redline.auth.guest' => true, 'redline.auth.guest_origins' => 'https://elsewhere.example']);

    $this->postJson('/redline/api/report', makeReportData(), ['Origin' => 'https://example.test'])
        ->assertStatus(403)
        ->assertJson(['message' => 'Forbidden origin.'])
        ->assertJsonMissingPath('error')
        ->assertHeaderMissing('WWW-Authenticate');
});

it('does not mark the CSRF 419 with the error field'.' (cookie-authenticated state change, no header echo)', function () {
    $user = makeUser('developer');
    $cookieToken = makeAuthToken($user);

    $this->withUnencryptedCookies(['redline_token' => $cookieToken])
        ->withCredentials()
        ->postJson('/redline/api/report', makeReportData())
        ->assertStatus(419)
        ->assertJson(['message' => 'CSRF token mismatch.'])
        ->assertJsonMissingPath('error')
        ->assertHeaderMissing('WWW-Authenticate');
});

// ---------------------------------------------------------------------------
// Presented-but-invalid credentials via a CORRUPT redline_token cookie
// ---------------------------------------------------------------------------

it('does not challenge on auth/me with a corrupt redline_token cookie', function () {
    $this->withUnencryptedCookies(['redline_token' => 'garbage.cookie.value'])
        ->withCredentials()
        ->getJson('/redline/api/auth/me')
        ->assertStatus(403)
        ->assertJson(['error' => 'unauthenticated'])
        ->assertHeaderMissing('WWW-Authenticate');
});

// ---------------------------------------------------------------------------
// HEAD /auth/me notes the GET-only anonymous branch: the framework treats
// HEAD requests as GET responses but RedlineApiAuth's anonymous pass-through
// is GET-only by design, so a HEAD probe with NO credentials still falls
// through to the anonymous path via the same middleware only when isMethod
// matches - i.e. HEAD answers like any protected route (403 + marker).
// ---------------------------------------------------------------------------

it('answers a HEAD /auth/me probe with the denial, not an anonymous 200', function () {
    $this->call('HEAD', '/redline/api/auth/me')
        ->assertStatus(403)
        ->assertHeaderMissing('WWW-Authenticate');
});

// ---------------------------------------------------------------------------
// Named regression: a garbage Bearer is a denial, never a 500 (the pre-v3
// latent bug - an escaped DomainException from firebase/php-jwt).
// ---------------------------------------------------------------------------

it('rejects a garbage Bearer with the denial envelope on a non-me endpoint', function () {
    $this->getJson('/redline/api/user', ['Authorization' => 'Bearer '.base64_encode('jwt.garbage').'garbage'])
        ->assertStatus(403)
        ->assertJson(['error' => 'unauthenticated'])
        ->assertHeaderMissing('WWW-Authenticate');
});

// ---------------------------------------------------------------------------
// Framework render path: Handler::render() answers the Unauthenticated
// exception with the v3 envelope without any host-side registration.
// ---------------------------------------------------------------------------

it('renders the Unauthenticated exception through the framework handler', function () {
    $handler = $this->app->make(ExceptionHandler::class);

    $response = $handler->render(
        Request::create('/redline/api/user'),
        new Unauthenticated,
    );

    expect($response->getStatusCode())->toBe(403)
        ->and(json_decode($response->getContent(), true))->toBe([
            'message' => 'Unauthenticated.',
            'error' => 'unauthenticated',
        ]);
});

// ---------------------------------------------------------------------------
// Custom REDLINE_PATH (boot-time, as a host .env writing REDLINE_PATH=\"/redline\"
// would produce): the path must never miss a Request::is() comparison - that
// regression used to restore the anonymous-me 403 AND downgrade the MCP 401.
// ---------------------------------------------------------------------------

/** Boot with a leading-slash REDLINE_PATH (the regressor shape). */
function bootWithLeadingSlashPath(): void
{
    TestCase::bootWithPath('/redline');

    test()->refreshApplication();
    test()->bootRedlineForTests();
}

/** Restore the suite's default boot - a test must never leave boot overrides
 * set for later tests in the same run (see RouteGuardCacheTest). */
function bootBackAfterLeadingSlashPath(): void
{
    TestCase::bootWithPath(null);

    test()->refreshApplication();
    test()->bootRedlineForTests();
}

it('keeps auth/me anonymous under a leading-slash REDLINE_PATH', function () {
    bootWithLeadingSlashPath();

    try {
        $this->getJson('/redline/api/auth/me')
            ->assertOk()
            ->assertJson(['user' => null])
            ->assertHeaderMissing('WWW-Authenticate');
    } finally {
        bootBackAfterLeadingSlashPath();
    }
});

it('keeps serving the browser API surface under a leading-slash REDLINE_PATH', function () {
    bootWithLeadingSlashPath();

    try {
        $this->getJson('/redline/api/report')
            ->assertStatus(403)
            ->assertJson(['error' => 'unauthenticated'])
            ->assertHeaderMissing('WWW-Authenticate');
    } finally {
        bootBackAfterLeadingSlashPath();
    }
});

it('keeps the MCP surface on v2 under a leading-slash REDLINE_PATH: 401 and challenge', function () {
    bootWithLeadingSlashPath();

    try {
        $this->post('/redline/api/mcp', initializeMcpBody())
            ->assertUnauthorized()
            ->assertHeader('WWW-Authenticate');
    } finally {
        bootBackAfterLeadingSlashPath();
    }
});
// ---------------------------------------------------------------------------
// Only 'Bearer ' is parsed - an HTTP Basic Authorization header presents no
// redline credentials at all, so me() completes anonymously (never a 401).
// ---------------------------------------------------------------------------

it('treats an Authorization header without a Bearer prefix as no credentials', function () {
    $basic = 'Basic '.base64_encode('reporter@example.com:secret123');

    $this->getJson('/redline/api/auth/me', ['Authorization' => $basic])
        ->assertOk()
        ->assertJson(['user' => null])
        ->assertHeaderMissing('WWW-Authenticate');
});

// ---------------------------------------------------------------------------
// The MCP branch must fail CLOSED on path variance. Empirically (pinned):
// mixed case is a route miss behind the SPA catch-all (405 method gate, no
// auth decision, no challenge); doubled slashes are URI-normalized by
// Symfony, so they ARE the MCP route and keep its contract 401 + challenge
// - neither leaks credentials nor puts the challenge on the browser surface.
// ---------------------------------------------------------------------------

it('answers mixed-case MCP path misses with no auth decision or challenge', function () {
    $this->postJson('/redline/API/mcp', initializeMcpBody())
        ->assertStatus(405)
        ->assertHeaderMissing('WWW-Authenticate');
});

it('keeps the MCP contract on doubled-slash MCP paths (URI-normalized)', function () {
    $this->postJson('//redline/api/mcp', initializeMcpBody())
        ->assertUnauthorized()
        ->assertHeader('WWW-Authenticate');
});
it('keeps the MCP route as the contrast case: 401 with the vendor challenge', function () {
    $this->postJson('/redline/api/mcp', [])
        ->assertUnauthorized()
        ->assertHeader('WWW-Authenticate');
});
