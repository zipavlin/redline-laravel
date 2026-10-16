<?php

use Zipavlin\Redline\Models\Report;
use Zipavlin\Redline\Models\Screenshot;
use Zipavlin\Redline\Models\Token;
use Zipavlin\Redline\Models\User;

/**
 * Dispatch/auth matrix for the RedlineApiAuth bearer shape on the MCP route.
 * The JWT path itself is byte-for-byte unchanged and covered by the existing
 * suite - these tests focus on the new `rlm_` API-token dispatch.
 */
test('a valid rlm_ bearer authenticates an MCP initialize handshake', function () {
    ['token' => $token] = makeApiToken();

    $response = $this->postJson('/redline/api/mcp', initializeMcpBody(), [
        'Authorization' => 'Bearer '.$token,
    ]);

    $response->assertOk()
        ->assertJsonPath('jsonrpc', '2.0')
        ->assertJsonPath('result.serverInfo.name', 'redline');
});

test('the same rlm_ bearer works on an existing protected HTTP endpoint', function () {
    ['token' => $token] = makeApiToken('developer');
    app(Report::class)->create(makeReportData());

    $this->get('/redline/api/report', ['Authorization' => 'Bearer '.$token])
        ->assertOk()
        ->assertJsonStructure(['data' => []]);
});

test('the same rlm_ bearer works on the screenshot binary endpoint', function () {
    ensureRedlineDirectories();

    ['user' => $user, 'token' => $token] = makeApiToken('developer');
    $report = app(Report::class)->create(['author_id' => $user->id, ...makeReportData()]);

    $file = newScreenshotFilePath();
    app(Screenshot::class)->create(['id' => (string) Str::uuid(), 'report_id' => $report->id, 'path' => $file]);
    $screenshotId = app(Screenshot::class)->where('report_id', $report->id)->value('id');

    $this->get("/redline/api/report/{$report->id}/screenshot/{$screenshotId}/binary", [
        'Authorization' => 'Bearer '.$token,
    ])->assertOk();
});

test('an unknown rlm_ token is rejected with the contract envelope and WWW-Authenticate', function () {
    $this->postJson('/redline/api/mcp', initializeMcpBody(), [
        'Authorization' => 'Bearer rlm_'.str_repeat('0', 40),
    ])
        ->assertUnauthorized()
        ->assertJson(['message' => 'Invalid or expired token.'])
        ->assertHeader('WWW-Authenticate');
});

test('a revoked token is rejected', function () {
    ['token' => $token] = makeApiToken();

    // Revocation by hand - the command path is covered in McpCommand tests.
    app(Token::class)->where('token_hash', Token::hash($token))->first()->forceFill([
        'revoked_at' => now(),
    ])->save();

    $this->postJson('/redline/api/mcp', initializeMcpBody(), ['Authorization' => 'Bearer '.$token])
        ->assertUnauthorized()
        ->assertJson(['message' => 'Invalid or expired token.']);
});

test('a password change invalidates outstanding tokens', function () {
    ['user' => $user, 'token' => $token] = makeApiToken();

    $user->update(['password' => Hash::make('a-new-password')]);

    $this->postJson('/redline/api/mcp', initializeMcpBody(), ['Authorization' => 'Bearer '.$token])
        ->assertUnauthorized()
        ->assertJson(['message' => 'Unauthenticated.']);
});

test('plain logout does NOT invalidate an outstanding rlm_ token', function () {
    ['user' => $user, 'token' => $token] = makeApiToken();

    // Logout advances token_version only - password_epoch is the one
    // invalidation for API tokens.
    $user->bumpTokenVersion();

    $this->postJson('/redline/api/mcp', initializeMcpBody(), ['Authorization' => 'Bearer '.$token])
        ->assertOk();
});

test('a pending token owner is rejected', function () {
    ['token' => $token] = makeApiToken('developer', ['status' => 'pending']);

    $this->postJson('/redline/api/mcp', initializeMcpBody(), ['Authorization' => 'Bearer '.$token])
        ->assertUnauthorized()
        ->assertJson(['message' => 'Unauthenticated.']);
});

test('a guest token owner is rejected', function () {
    $guest = app(User::class)->create([
        'name' => 'Guest',
        'email' => 'guest-'.Str::uuid().'@redline.local',
        'role' => 'guest',
    ]);

    $token = tokenFor($guest);

    $this->postJson('/redline/api/mcp', initializeMcpBody(), ['Authorization' => 'Bearer '.$token])
        ->assertUnauthorized()
        ->assertJson(['message' => 'Unauthenticated.']);
});

test('an rlm_-shaped cookie value is explicitly rejected on the MCP route', function () {
    $this->withUnencryptedCookies(['redline_token' => tokenFor(makeTokenOwner())])
        ->postJson('/redline/api/mcp', initializeMcpBody())
        ->assertUnauthorized()
        ->assertJson(['message' => 'Unauthenticated.']);
});

test('an rlm_-shaped cookie value is explicitly rejected on existing protected endpoints', function () {
    $this->withUnencryptedCookies(['redline_token' => tokenFor(makeTokenOwner())])
        ->get('/redline/api/report')
        ->assertUnauthorized()
        ->assertJson(['message' => 'Unauthenticated.']);
});

test('every 401 from the MCP route carries WWW-Authenticate', function () {
    $this->postJson('/redline/api/mcp', initializeMcpBody(), [
        'Authorization' => 'Bearer rlm_'.str_repeat('0', 40),
    ])->assertHeader('WWW-Authenticate');
});
