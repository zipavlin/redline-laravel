<?php

use Zipavlin\Redline\Providers\Auth\ApprovalAuthProvider;
use Zipavlin\Redline\Providers\Auth\AuthProvider;
use Zipavlin\Redline\Providers\Auth\ClosedAuthProvider;
use Zipavlin\Redline\Providers\Auth\OpenAuthProvider;
use Zipavlin\Redline\Providers\Auth\PinAuthProvider;

beforeEach(function () {
    $this->app->singleton(AuthProvider::class, OpenAuthProvider::class);
});

// ---------------------------------------------------------------------------
// Register - new user (OpenAuthProvider)
// ---------------------------------------------------------------------------

it('registers a new user and sets the auth + CSRF cookies without exposing the token', function () {
    $response = $this->postJson('/redline/api/auth/register', [
        'name' => 'Jane Tester',
        'email' => 'jane@example.com',
        'password' => 'secret123',
        'role' => 'reporter',
    ])->assertStatus(200);

    $body = $response->json();

    expect($body)->toHaveKey('user')
        ->and($body)->not->toHaveKey('token')
        ->and($body['user']['email'])->toBe('jane@example.com')
        ->and($body['user']['role'])->toBe('reporter');

    // The JWT travels only as an HttpOnly cookie (never in JSON); the CSRF cookie
    // is JS-readable so the frontend can echo it back in the X-Redline-CSRF header.
    $cookies = collect($response->baseResponse->headers->getCookies());

    $tokenCookie = $cookies->first(fn ($c) => $c->getName() === 'redline_token');
    $csrfCookie = $cookies->first(fn ($c) => $c->getName() === 'redline_csrf');

    expect($tokenCookie)->not->toBeNull()
        ->and($tokenCookie->isHttpOnly())->toBeTrue()
        ->and($tokenCookie->getValue())->toBeString()->not->toBeEmpty()
        ->and($csrfCookie)->not->toBeNull()
        ->and($csrfCookie->isHttpOnly())->toBeFalse()
        ->and($csrfCookie->getValue())->toBeString()->not->toBeEmpty();
});

it('does NOT auto-promote or grant admin via public registration by default', function () {
    // Secure default: the first developer stays a developer...
    $dev = $this->postJson('/redline/api/auth/register', [
        'name' => 'First Dev', 'email' => 'dev@example.com', 'password' => 'secret123', 'role' => 'developer',
    ])->assertStatus(200)->json();

    expect($dev['user']['role'])->toBe('developer');

    // ...and an explicit role=admin request is downgraded to reporter.
    $wannabe = $this->postJson('/redline/api/auth/register', [
        'name' => 'Wannabe', 'email' => 'admin@example.com', 'password' => 'secret123', 'role' => 'admin',
    ])->assertStatus(200)->json();

    expect($wannabe['user']['role'])->toBe('reporter');
});

it('auto-promotes the first developer/admin when allow_self_admin is opted in', function () {
    config(['redline.auth.allow_self_admin' => true]);

    $body = $this->postJson('/redline/api/auth/register', [
        'name' => 'First Dev', 'email' => 'dev@example.com', 'password' => 'secret123', 'role' => 'developer',
    ])->assertStatus(200)->json();

    expect($body['user']['role'])->toBe('admin');
});

// ---------------------------------------------------------------------------
// Login - existing user
// ---------------------------------------------------------------------------

it('returns 200 and sets the auth cookie when logging in an existing user', function () {
    // Register first.
    $this->postJson('/redline/api/auth/register', [
        'name' => 'Jane', 'email' => 'jane@example.com', 'password' => 'secret123', 'role' => 'reporter',
    ])->assertStatus(200);

    // Login.
    $response = $this->postJson('/redline/api/auth/login', [
        'email' => 'jane@example.com', 'password' => 'secret123',
    ])->assertStatus(200);

    $body = $response->json();

    expect($body)->toHaveKey('user')
        ->and($body)->not->toHaveKey('token');

    // The JWT is delivered as an HttpOnly cookie, not in the JSON body.
    $tokenCookie = collect($response->baseResponse->headers->getCookies())
        ->first(fn ($c) => $c->getName() === 'redline_token');

    expect($tokenCookie)->not->toBeNull()
        ->and($tokenCookie->isHttpOnly())->toBeTrue()
        ->and($tokenCookie->getValue())->toBeString()->not->toBeEmpty();
});

it('returns 401 when the password is wrong', function () {
    $this->postJson('/redline/api/auth/register', [
        'name' => 'Jane', 'email' => 'jane@example.com', 'password' => 'secret123', 'role' => 'reporter',
    ])->assertStatus(200);

    $this->postJson('/redline/api/auth/login', [
        'email' => 'jane@example.com', 'password' => 'wrong',
    ])->assertStatus(401);
});

it('returns a uniform 401 (not 404) when no account exists, to avoid email enumeration', function () {
    $this->postJson('/redline/api/auth/login', [
        'email' => 'nobody@example.com', 'password' => 'secret123',
    ])->assertStatus(401);
});

// ---------------------------------------------------------------------------
// PIN provider
// ---------------------------------------------------------------------------

it('returns 422 with a pin error when no valid PIN is supplied', function () {
    $this->app->singleton(AuthProvider::class, PinAuthProvider::class);
    config(['redline.auth.pin.developer' => 'correct-pin', 'redline.auth.pin.reporter' => null]);

    $body = $this->postJson('/redline/api/auth/register', [
        'name' => 'Dev',
        'email' => 'dev@example.com',
        'password' => 'secret123',
        'pin' => 'wrong-pin',
    ])
        ->assertStatus(422)
        ->json();

    expect(collect($body['errors'])->flatten()->implode(' '))->toContain('Incorrect PIN.');
});

it('returns 200 when the correct PIN is supplied', function () {
    $this->app->singleton(AuthProvider::class, PinAuthProvider::class);
    config(['redline.auth.pin.developer' => 'correct-pin', 'redline.auth.pin.reporter' => null]);

    $this->postJson('/redline/api/auth/register', [
        'name' => 'Dev',
        'email' => 'dev@example.com',
        'password' => 'secret123',
        'pin' => 'correct-pin',
    ])
        ->assertStatus(200);
});

it('accepts a numeric PIN on register', function () {
    $this->app->singleton(AuthProvider::class, PinAuthProvider::class);
    config(['redline.auth.pin.developer' => '1234', 'redline.auth.pin.reporter' => null]);

    $body = $this->postJson('/redline/api/auth/register', [
        'name' => 'Dev',
        'email' => 'dev@example.com',
        'password' => 'secret123',
        'pin' => 1234,
    ])
        ->assertStatus(200)
        ->json();

    expect($body['user']['role'])->toBe('developer');
});

it('returns 422 for a wrong numeric PIN', function () {
    $this->app->singleton(AuthProvider::class, PinAuthProvider::class);
    config(['redline.auth.pin.developer' => '1234', 'redline.auth.pin.reporter' => null]);

    $body = $this->postJson('/redline/api/auth/register', [
        'name' => 'Dev',
        'email' => 'dev@example.com',
        'password' => 'secret123',
        'pin' => 9999,
    ])
        ->assertStatus(422)
        ->json();

    expect(collect($body['errors'])->flatten()->implode(' '))->toContain('Incorrect PIN.');
});

// ---------------------------------------------------------------------------
// Approval provider
// ---------------------------------------------------------------------------

it('returns 202 pending when using ApprovalAuthProvider', function () {
    $this->app->singleton(AuthProvider::class, ApprovalAuthProvider::class);

    $body = $this->postJson('/redline/api/auth/register', [
        'name' => 'Alice',
        'email' => 'alice@example.com',
        'password' => 'secret123',
        'role' => 'reporter',
    ])
        ->assertStatus(202)
        ->json();

    expect($body['pending'])->toBeTrue();
});

it('returns 202 pending when an existing pending user tries to log in', function () {
    $this->app->singleton(AuthProvider::class, ApprovalAuthProvider::class);

    $this->postJson('/redline/api/auth/register', [
        'name' => 'Alice', 'email' => 'alice@example.com', 'password' => 'secret123', 'role' => 'reporter',
    ])->assertStatus(202);

    $this->postJson('/redline/api/auth/login', [
        'email' => 'alice@example.com', 'password' => 'secret123',
    ])->assertStatus(202);
});

// ---------------------------------------------------------------------------
// Closed provider
// ---------------------------------------------------------------------------

it('returns 403 when registration is closed', function () {
    $this->app->singleton(AuthProvider::class, ClosedAuthProvider::class);

    $this->postJson('/redline/api/auth/register', [
        'name' => 'Bob',
        'email' => 'bob@example.com',
        'password' => 'secret123',
    ])->assertStatus(403);
});

// ---------------------------------------------------------------------------
// Validation
// ---------------------------------------------------------------------------

// Register returns field-keyed 422 errors (`{ field: [messages] }`) so the
// frontend can render per-field errors; these assertions flatten the map back
// to plain text purely for message matching.
function registerErrorText(array $payload): string
{
    return collect(test()->postJson('/redline/api/auth/register', $payload)
        ->assertStatus(422)
        ->json('errors'))
        ->flatten()
        ->implode(' ');
}

it('returns 422 when name is missing', function () {
    expect(registerErrorText([
        'email' => 'jane@example.com', 'password' => 'secret123', 'role' => 'reporter',
    ]))->toContain('name');
});

it('returns 422 when email is invalid', function () {
    $body = test()->postJson('/redline/api/auth/register', [
        'name' => 'Jane', 'email' => 'not-an-email', 'password' => 'secret123', 'role' => 'reporter',
    ])->assertStatus(422)->json();

    // Errors stay keyed by field so the frontend can render per-field messages.
    expect($body['errors'])->toHaveKey('email')
        ->and(collect($body['errors'])->flatten()->implode(' '))->toContain('email');
});

it('returns 422 when password is missing', function () {
    expect(registerErrorText([
        'name' => 'Jane', 'email' => 'jane@example.com', 'role' => 'reporter',
    ]))->toContain('password');
});

it('returns 422 when password is too short', function () {
    expect(registerErrorText([
        'name' => 'Jane', 'email' => 'jane@example.com', 'password' => 'short', 'role' => 'reporter',
    ]))->toContain('password');
});

it('returns 422 when role is invalid', function () {
    expect(registerErrorText([
        'name' => 'Jane', 'email' => 'jane@example.com', 'password' => 'secret123', 'role' => 'superuser',
    ]))->toContain('role');
});

it('returns 422 (not 500) when registering an already-taken email', function () {
    $this->postJson('/redline/api/auth/register', [
        'name' => 'First', 'email' => 'dup@example.com', 'password' => 'secret123', 'role' => 'reporter',
    ])->assertStatus(200);

    $body = $this->postJson('/redline/api/auth/register', [
        'name' => 'Second', 'email' => 'dup@example.com', 'password' => 'secret123', 'role' => 'reporter',
    ])
        ->assertStatus(422)
        ->json();

    expect(collect($body['errors'])->flatten()->implode(' '))->toContain('already been taken');
});

// ---------------------------------------------------------------------------
// Logout
// ---------------------------------------------------------------------------

it('returns 200 on logout', function () {
    $headers = authHeaders('reporter');

    $this->deleteJson('/redline/api/auth', [], $headers)
        ->assertStatus(200)
        ->assertJson(['message' => 'Logged out.']);
});

it('returns 401 on logout without a token', function () {
    $this->deleteJson('/redline/api/auth')
        ->assertStatus(401);
});

// ---------------------------------------------------------------------------
// Security
// ---------------------------------------------------------------------------

it('never includes the password in any response', function () {
    $body = $this->postJson('/redline/api/auth/register', [
        'name' => 'Jane', 'email' => 'jane@example.com', 'password' => 'super-secret-pw', 'role' => 'reporter',
    ])->getContent();

    expect($body)->not->toContain('super-secret-pw');
});
