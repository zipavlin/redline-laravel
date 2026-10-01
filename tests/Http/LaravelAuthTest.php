<?php

use Illuminate\Auth\GenericUser;
use Zipavlin\Redline\Enums\Role;
use Zipavlin\Redline\Models\User;
use Zipavlin\Redline\Providers\Auth\AuthProvider;
use Zipavlin\Redline\Providers\Auth\LaravelAuthProvider;
use Zipavlin\Redline\Providers\Auth\OpenAuthProvider;
use Zipavlin\Redline\Providers\Guard\LaravelGuardProvider;

/**
 * Register a web-group HTML route so widget injection is exercised. Mirrors the
 * helper route used by InjectRedlineTest.
 */
beforeEach(function () {
    $this->app['router']
        ->get('/__test/laravel-html', fn () => response(
            '<html><head><title>Host</title></head><body><h1>Host page</h1></body></html>',
            200,
            ['Content-Type' => 'text/html; charset=utf-8'],
        ))
        ->middleware('web');
});

function logIntoHost(string $email = 'host@example.com', string $name = 'Host User'): GenericUser
{
    $user = new GenericUser(['id' => 1, 'name' => $name, 'email' => $email]);

    test()->actingAs($user);

    return $user;
}

// ---------------------------------------------------------------------------
// Bootstrap capability manifest
// ---------------------------------------------------------------------------

it('reports login_mode session and closed registration for LaravelAuthProvider', function () {
    $this->app->singleton(AuthProvider::class, LaravelAuthProvider::class);

    $this->getJson('/redline/api/bootstrap')
        ->assertStatus(200)
        ->assertJsonPath('capabilities.auth.login_mode', 'session')
        ->assertJsonPath('capabilities.auth.can_login', true)
        ->assertJsonPath('capabilities.auth.can_register', false)
        ->assertJsonPath('capabilities.auth.can_select_role', false);
});

it('reports login_mode credentials for the base providers', function () {
    $this->app->singleton(AuthProvider::class, OpenAuthProvider::class);

    $this->getJson('/redline/api/bootstrap')
        ->assertStatus(200)
        ->assertJsonPath('capabilities.auth.login_mode', 'credentials');
});

// ---------------------------------------------------------------------------
// Registration stays closed
// ---------------------------------------------------------------------------

it('returns 403 on register under LaravelAuthProvider', function () {
    $this->app->singleton(AuthProvider::class, LaravelAuthProvider::class);

    $this->postJson('/redline/api/auth/register', [
        'name' => 'Bob', 'email' => 'bob@example.com', 'password' => 'secret123',
    ])->assertStatus(403);
});

// ---------------------------------------------------------------------------
// Dashboard shell gate (LaravelGuardProvider)
// ---------------------------------------------------------------------------

it('serves the dashboard shell when the host application user is logged in', function () {
    config(['redline.guard.provider' => LaravelGuardProvider::class]);

    logIntoHost();

    $this->get('/redline')
        ->assertStatus(200)
        ->assertSee('redline-dashboard', false);
});

it('returns 403 on the dashboard shell when no host user is logged in', function () {
    config(['redline.guard.provider' => LaravelGuardProvider::class]);

    $this->get('/redline')->assertStatus(403);
});

// ---------------------------------------------------------------------------
// Widget injection (LaravelGuardProvider)
// ---------------------------------------------------------------------------

it('does not inject the widget when the host application user is logged out', function () {
    config(['redline.guard.provider' => LaravelGuardProvider::class]);

    expect($this->get('/__test/laravel-html')->getContent())
        ->not->toContain('redline-widget');
});

it('injects the widget when the host application user is logged in', function () {
    config(['redline.guard.provider' => LaravelGuardProvider::class]);

    logIntoHost();

    expect($this->get('/__test/laravel-html')->getContent())
        ->toContain('redline-widget');
});

// ---------------------------------------------------------------------------
// Session exchange (route level)
// ---------------------------------------------------------------------------
// NOTE (M-2): `actingAs` resolves from the preset user via SessionGuard, so these
// tests exercise the controller + provider handshake but not a real browser
// session cookie travelling through EncryptCookies/StartSession.
// ---------------------------------------------------------------------------

it('exchanges the host session for a redline JWT on an empty-body login', function () {
    $this->app->singleton(AuthProvider::class, LaravelAuthProvider::class);

    logIntoHost('session@example.com', 'Session User');

    $response = $this->postJson('/redline/api/auth/login', [])->assertStatus(200);

    $body = $response->json();

    expect($body)->toHaveKey('user')
        ->and($body['user']['email'])->toBe('session@example.com')
        ->and($body['user']['role'])->toBe('reporter');

    $tokenCookie = collect($response->baseResponse->headers->getCookies())
        ->first(fn ($c) => $c->getName() === 'redline_token');

    expect($tokenCookie)->not->toBeNull()
        ->and($tokenCookie->isHttpOnly())->toBeTrue()
        ->and($tokenCookie->getValue())->toBeString()->not->toBeEmpty();

    $created = app(User::class)->where('email', 'session@example.com')->first();

    expect($created)->not->toBeNull()
        ->and($created->role)->toBe(Role::REPORTER)
        ->and($created->status)->toBe('approved');

    // A second identical POST reuses the same redline user - no duplication.
    $second = $this->postJson('/redline/api/auth/login', [])->assertStatus(200)->json();

    expect($second['user']['id'])->toBe($body['user']['id'])
        ->and(app(User::class)->where('email', 'session@example.com')->count())->toBe(1);
});

it('returns 401 on login when no host application user is logged in', function () {
    $this->app->singleton(AuthProvider::class, LaravelAuthProvider::class);

    $this->postJson('/redline/api/auth/login', [])
        ->assertStatus(401)
        ->assertJsonPath('errors.0', 'Not authenticated with the host application.');
});

it('rejects a session login from a foreign Origin with 403', function () {
    $this->app->singleton(AuthProvider::class, LaravelAuthProvider::class);

    logIntoHost('origin@example.com');

    $this->postJson('/redline/api/auth/login', [], ['Origin' => 'https://evil.example'])
        ->assertStatus(403)
        ->assertJson(['message' => 'Forbidden origin.']);
});

it('accepts a session login from the same-origin Origin', function () {
    $this->app->singleton(AuthProvider::class, LaravelAuthProvider::class);

    logIntoHost('same-origin@example.com');

    $this->postJson('/redline/api/auth/login', [], ['Origin' => url('/')])
        ->assertStatus(200);
});

it('accepts a session login from an origin listed in allowed_origins', function () {
    $this->app->singleton(AuthProvider::class, LaravelAuthProvider::class);

    config(['redline.auth.allowed_origins' => 'https://proxy.example']);

    logIntoHost('proxy-origin@example.com');

    $this->postJson('/redline/api/auth/login', [], ['Origin' => 'https://proxy.example'])
        ->assertStatus(200);
});

it('ignores the remember flag in session mode and issues a non-remember token', function () {
    $this->app->singleton(AuthProvider::class, LaravelAuthProvider::class);

    logIntoHost('remember@example.com');

    $response = $this->postJson('/redline/api/auth/login', ['remember' => true])->assertStatus(200);

    $token = collect($response->baseResponse->headers->getCookies())
        ->first(fn ($c) => $c->getName() === 'redline_token')
        ->getValue();

    $payload = json_decode(base64_decode(explode('.', $token)[1]), true);

    expect($payload['remember'])->toBeFalse();
});
