<?php

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\URL;
use Zipavlin\Redline\Enums\Role;
use Zipavlin\Redline\Models\User;
use Zipavlin\Redline\Providers\Auth\AuthProvider;
use Zipavlin\Redline\Providers\Auth\DomainAuthProvider;

beforeEach(function () {
    ensureRedlineDirectories();
});

// ---------------------------------------------------------------------------
// S1 - the email-verify link must be a valid signed URL
// ---------------------------------------------------------------------------

it('S1: rejects the verify endpoint without a valid signature (422 JSON for API clients)', function () {
    $this->app->singleton(AuthProvider::class, DomainAuthProvider::class);
    config(['redline.auth.domains' => 'example.com']);

    app(User::class)->create([
        'name' => 'Pending', 'email' => 'p@example.com',
        'password' => Hash::make('secret123'), 'role' => 'reporter', 'status' => 'pending',
    ]);

    // No signature → 422 JSON envelope (cannot approve an arbitrary account by
    // guessing the email; browsers get a friendly HTML page instead).
    $this->getJson('/redline/api/auth/verify?email=p@example.com')
        ->assertStatus(422)
        ->assertJson(['errors' => ['Invalid or expired verification link.'], 'pending' => false]);
});

it('S1: accepts the verify endpoint with a valid signed URL and approves the account', function () {
    $this->app->singleton(AuthProvider::class, DomainAuthProvider::class);
    config(['redline.auth.domains' => 'example.com']);

    app(User::class)->create([
        'name' => 'Pending', 'email' => 'p@example.com',
        'password' => Hash::make('secret123'), 'role' => 'reporter', 'status' => 'pending',
    ]);

    $url = URL::temporarySignedRoute('redline.auth.verify', now()->addHour(), ['email' => 'p@example.com']);

    $this->getJson($url)->assertStatus(200)->assertJsonPath('user.email', 'p@example.com');

    expect(app(User::class)->where('email', 'p@example.com')->first()->status)->toBe('approved');
});

it('S1: shows the success HTML page to a browser with a valid signed URL', function () {
    $this->app->singleton(AuthProvider::class, DomainAuthProvider::class);
    config(['redline.auth.domains' => 'example.com']);

    app(User::class)->create([
        'name' => 'Pending', 'email' => 'p@example.com',
        'password' => Hash::make('secret123'), 'role' => 'reporter', 'status' => 'pending',
    ]);

    $url = URL::temporarySignedRoute('redline.auth.verify', now()->addHour(), ['email' => 'p@example.com']);

    // Plain browser GET (no Accept: application/json) must hit the HTML page.
    $this->get($url)
        ->assertStatus(200)
        ->assertSee('Your account was registered successfully.')
        ->assertSee('http://localhost/redline');

    expect(app(User::class)->where('email', 'p@example.com')->first()->status)->toBe('approved');
});

it('S1: shows the invalid-link HTML page to a browser without a signature', function () {
    $this->app->singleton(AuthProvider::class, DomainAuthProvider::class);
    config(['redline.auth.domains' => 'example.com']);

    $this->get('/redline/api/auth/verify?email=p@example.com')
        ->assertStatus(200)
        ->assertSee('Invalid or expired verification link.')
        ->assertSee('http://localhost/redline');
});

// ---------------------------------------------------------------------------
// S2 - stored XSS: rich-text bodies are sanitized server-side on write
// ---------------------------------------------------------------------------

it('S2: strips scripts and event handlers from report bodies', function () {
    $body = $this->postJson('/redline/api/report', makeReportData([
        'body' => '<p>hi</p><script>alert(1)</script><img src=x onerror=alert(1)>',
    ]), authHeaders('reporter'))->assertStatus(201)->json();

    expect($body['body'])
        ->not->toContain('<script')
        ->not->toContain('onerror')
        ->not->toContain('<img')
        ->toContain('<p>hi</p>');
});

it('S2: strips scripts from comment bodies', function () {
    $report = $this->postJson('/redline/api/report', makeReportData(), authHeaders('developer'))
        ->assertStatus(201)->json();

    $comment = $this->postJson("/redline/api/report/{$report['id']}/comment", [
        'body' => 'ok<script>steal()</script>',
    ], authHeaders('developer'))->assertStatus(201)->json();

    expect($comment['body'])->not->toContain('<script');
});

it('S2: preserves a TipTap mention span and its data-id on write', function () {
    $mention = '<p>Ping <span data-type="mention" class="mention" data-id="user-42" '
        .'data-label="Jane Doe" data-mention-suggestion-char="@">@Jane Doe</span></p>';

    $body = $this->postJson('/redline/api/report', makeReportData(['body' => $mention]), authHeaders('reporter'))
        ->assertStatus(201)->json();

    expect($body['body'])
        ->toContain('data-type="mention"')
        ->toContain('data-id="user-42"')
        ->toContain('data-label="Jane Doe"')
        ->toContain('data-mention-suggestion-char="@"')
        ->toContain('class="mention"')
        ->toContain('@Jane Doe');
});

it('S2: strips event handlers, styles and scripts from a forged mention span', function () {
    $forged = '<p><span data-type="mention" data-id="x" data-evil="1" onclick="alert(1)" style="color:red">'
        .'<script>alert(2)</script>@Eve</span></p>';

    $body = $this->postJson('/redline/api/report', makeReportData(['body' => $forged]), authHeaders('reporter'))
        ->assertStatus(201)->json();

    expect($body['body'])
        ->not->toContain('onclick')
        ->not->toContain('style=')
        ->not->toContain('<script')
        ->not->toContain('data-evil')
        ->toContain('@Eve');
});

// ---------------------------------------------------------------------------
// S4 - auth endpoints are rate-limited
// ---------------------------------------------------------------------------

it('S4: throttles repeated login attempts with 429', function () {
    $last = null;
    for ($i = 0; $i < 12; $i++) {
        $last = $this->postJson('/redline/api/auth/login', [
            'email' => 'nobody@example.com', 'password' => 'wrong-password',
        ]);
    }

    $last->assertStatus(429);
});

// ---------------------------------------------------------------------------
// S6 - token revocation via token_version
// ---------------------------------------------------------------------------

it('S6: rejects a token after the user logs out', function () {
    $user = makeUser('developer');
    $headers = ['Authorization' => 'Bearer '.makeAuthToken($user)];

    $this->getJson('/redline/api/auth/me', $headers)->assertStatus(200);
    $this->deleteJson('/redline/api/auth', [], $headers)->assertStatus(200);
    $this->getJson('/redline/api/auth/me', $headers)->assertStatus(401);
});

it('S6: rejects a token after an admin resets the password', function () {
    $admin = ['Authorization' => 'Bearer '.makeAuthToken(makeUser('admin'))];
    $victim = makeUser('reporter');
    $victimHeaders = ['Authorization' => 'Bearer '.makeAuthToken($victim)];

    $this->getJson('/redline/api/auth/me', $victimHeaders)->assertStatus(200);
    $this->postJson("/redline/api/user/{$victim['id']}/reset-password", ['password' => 'brandnew123'], $admin)
        ->assertStatus(200);
    $this->getJson('/redline/api/auth/me', $victimHeaders)->assertStatus(401);
});

// ---------------------------------------------------------------------------
// S12 - a pending account is rejected at the middleware, not per-endpoint
// ---------------------------------------------------------------------------

it('S12: rejects a pending admin token on every protected endpoint', function () {
    $pendingAdmin = app(User::class)->create([
        'name' => 'Pending Admin', 'email' => 'pending-admin@example.com',
        'role' => 'admin', 'status' => 'pending',
    ]);
    $headers = ['Authorization' => 'Bearer '.makeAuthToken($pendingAdmin->toArray())];
    $target = makeUser('reporter');

    $this->postJson('/redline/api/user', [
        'name' => 'Created By Pending', 'email' => 'created-by-pending@example.com',
        'role' => 'reporter', 'password' => 'secret123',
    ], $headers)->assertStatus(401);

    $this->patchJson("/redline/api/user/{$target['id']}", ['role' => 'admin'], $headers)->assertStatus(401);
    $this->deleteJson("/redline/api/user/{$target['id']}", [], $headers)->assertStatus(401);
    $this->postJson("/redline/api/user/{$pendingAdmin->id}/approve", [], $headers)->assertStatus(401);
    $this->postJson("/redline/api/user/{$target['id']}/reset-password", ['password' => 'brandnew123'], $headers)
        ->assertStatus(401);
    $this->getJson('/redline/api/auth/refresh', $headers)->assertStatus(401);

    // None of the attempts changed anything.
    expect(app(User::class)->find($target['id'])->role)->toBe(Role::REPORTER)
        ->and(app(User::class)->find($pendingAdmin->id)->status)->toBe('pending');
});

it('S12: revokes the outstanding token when a user is moved back to pending', function () {
    $adminHeaders = ['Authorization' => 'Bearer '.makeAuthToken(makeUser('admin'))];
    $victim = makeUser('reporter');
    $victimHeaders = ['Authorization' => 'Bearer '.makeAuthToken($victim)];

    $this->getJson('/redline/api/auth/me', $victimHeaders)->assertStatus(200);

    $this->patchJson("/redline/api/user/{$victim['id']}", ['status' => 'pending'], $adminHeaders)
        ->assertStatus(200);

    expect((int) app(User::class)->find($victim['id'])->token_version)->toBe(1);

    $this->getJson('/redline/api/auth/me', $victimHeaders)->assertStatus(401);
});

it('S12: keeps token_version when pending is written to an already-pending user', function () {
    $adminHeaders = ['Authorization' => 'Bearer '.makeAuthToken(makeUser('admin'))];
    $pending = app(User::class)->create([
        'name' => 'Already Pending', 'email' => 'already-pending@example.com',
        'role' => 'reporter', 'status' => 'pending',
    ]);

    $this->patchJson("/redline/api/user/{$pending->id}", ['status' => 'pending'], $adminHeaders)
        ->assertStatus(200);

    expect((int) app(User::class)->find($pending->id)->token_version)->toBe(0);
});

// ---------------------------------------------------------------------------
// S7 - the ?token= query fallback is not accepted
// ---------------------------------------------------------------------------

it('S7: ignores a token supplied via the query string', function () {
    $token = makeAuthToken(makeUser('developer'));

    $this->getJson('/redline/api/auth/me?token='.$token)->assertStatus(401);
});

// ---------------------------------------------------------------------------
// S10 - refresh reads `remember` from the verified payload only
// ---------------------------------------------------------------------------

it('S10: refresh does not extend token lifetime from an unverified claim', function () {
    $headers = ['Authorization' => 'Bearer '.makeAuthToken(makeUser('developer'))];

    $response = $this->getJson('/redline/api/auth/refresh', $headers)->assertStatus(200);

    // The JWT no longer appears in the JSON body - it is carried in the HttpOnly
    // cookie, so decode the cookie value to inspect the claims.
    expect($response->json())->toHaveKey('user')->not->toHaveKey('token');

    $cookie = collect($response->baseResponse->headers->getCookies())
        ->first(fn ($c) => $c->getName() === 'redline_token');

    expect($cookie)->not->toBeNull();

    $payload = json_decode(base64_decode(explode('.', $cookie->getValue())[1]), true);

    expect($payload['remember'])->toBeFalse()
        ->and($payload['exp'] - $payload['iat'])->toBeLessThanOrEqual(3600);
});

// ---------------------------------------------------------------------------
// S9 - the last remaining admin cannot be removed
// ---------------------------------------------------------------------------

it('S9: refuses to delete the last remaining admin', function () {
    $admin = makeUser('admin');
    $headers = ['Authorization' => 'Bearer '.makeAuthToken($admin)];

    $this->deleteJson("/redline/api/user/{$admin['id']}", [], $headers)->assertStatus(422);
});

// ---------------------------------------------------------------------------
// S11 - media paths/ids are server-generated (no client-controlled traversal)
// ---------------------------------------------------------------------------

it('S11: ignores client-supplied id/path when storing a screenshot', function () {
    $headers = authHeaders('developer');
    $report = $this->postJson('/redline/api/report', makeReportData(), $headers)->assertStatus(201)->json();

    $res = $this->postJson("/redline/api/report/{$report['id']}/screenshot", [
        'screenshot' => UploadedFile::fake()->create('shot.png', 10, 'image/png'),
        'id' => '../../../evil',
        'path' => '../../evil',
    ], $headers)->assertStatus(201)->json();

    expect($res['id'])->not->toBe('../../../evil')
        ->and($res['path'])->not->toContain('..');
});
