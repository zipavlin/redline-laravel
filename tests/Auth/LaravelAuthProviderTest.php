<?php

use Illuminate\Auth\GenericUser;
use Zipavlin\Redline\Enums\Role;
use Zipavlin\Redline\Models\User;
use Zipavlin\Redline\Providers\Auth\LaravelAuthProvider;

function laravelAuthProvider(): LaravelAuthProvider
{
    return new LaravelAuthProvider;
}

function actAsHostUser(array $attributes = []): GenericUser
{
    $user = new GenericUser(array_merge([
        'id' => 1,
        'name' => 'Host User',
        'email' => 'host@example.com',
    ], $attributes));

    test()->actingAs($user);

    return $user;
}

// ---------------------------------------------------------------------------
// Login exchange
// ---------------------------------------------------------------------------

it('returns 401 when no host application user is logged in', function () {
    $result = laravelAuthProvider()->login([]);

    expect($result)->toBe(['error' => 'Not authenticated with the host application.', 'code' => 401]);
});

it('fails closed with 401 when the host user has no email address', function () {
    actAsHostUser(['email' => '']);

    $result = laravelAuthProvider()->login([]);

    expect($result)->toBe(['error' => 'Host user has no email address.', 'code' => 401]);
});

it('provisions a reporter on first login and copies name and email', function () {
    actAsHostUser(['name' => 'Ada Lovelace', 'email' => 'ada@example.com']);

    $result = laravelAuthProvider()->login([]);

    expect($result)->toHaveKey('user');

    $user = $result['user'];

    expect($user)->toBeInstanceOf(User::class)
        ->and($user->email)->toBe('ada@example.com')
        ->and($user->name)->toBe('Ada Lovelace')
        ->and($user->role)->toBe(Role::REPORTER)
        ->and($user->status)->toBe('approved')
        ->and($user->password)->toBeNull();
});

it('falls back to the email as name when the host user has no name', function () {
    actAsHostUser(['name' => '', 'email' => 'noname@example.com']);

    $user = laravelAuthProvider()->login([])['user'];

    expect($user->name)->toBe('noname@example.com');
});

it('returns the existing redline user untouched when the email matches', function () {
    $existing = app(User::class)->create([
        'name' => 'Existing Developer',
        'email' => 'dev@example.com',
        'role' => 'developer',
        'status' => 'approved',
    ]);

    actAsHostUser(['name' => 'Different Host Name', 'email' => 'dev@example.com']);

    $user = laravelAuthProvider()->login([])['user'];

    expect($user->id)->toBe($existing->id)
        ->and($user->name)->toBe('Existing Developer')
        ->and($user->role)->toBe(Role::DEVELOPER)
        ->and(app(User::class)->where('email', 'dev@example.com')->count())->toBe(1);
});

// ---------------------------------------------------------------------------
// Email join normalization (L-1)
// ---------------------------------------------------------------------------

it('matches a mixed-case stored redline email against a lowercase host email', function () {
    $existing = app(User::class)->create([
        'name' => 'Mixed Case',
        'email' => 'Foo@Example.com',
        'role' => 'developer',
        'status' => 'approved',
    ]);

    actAsHostUser(['email' => 'foo@example.com']);

    $user = laravelAuthProvider()->login([])['user'];

    expect($user->id)->toBe($existing->id)
        ->and(app(User::class)->count())->toBe(1);
});

it('matches a whitespace-padded stored redline email against a clean host email', function () {
    $existing = app(User::class)->create([
        'name' => 'Padded',
        'email' => '  padded@example.com  ',
        'role' => 'reporter',
        'status' => 'approved',
    ]);

    actAsHostUser(['email' => 'padded@example.com']);

    $user = laravelAuthProvider()->login([])['user'];

    expect($user->id)->toBe($existing->id)
        ->and(app(User::class)->count())->toBe(1);
});

it('matches a lowercase stored redline email against a mixed-case host email', function () {
    $existing = app(User::class)->create([
        'name' => 'Lower Stored',
        'email' => 'lower@example.com',
        'role' => 'reporter',
        'status' => 'approved',
    ]);

    actAsHostUser(['email' => 'LoWeR@Example.COM']);

    $user = laravelAuthProvider()->login([])['user'];

    expect($user->id)->toBe($existing->id)
        ->and(app(User::class)->count())->toBe(1);
});

it('stores the normalized email when provisioning', function () {
    actAsHostUser(['name' => 'Case User', 'email' => 'Case@Example.COM']);

    $user = laravelAuthProvider()->login([])['user'];

    expect($user->email)->toBe('case@example.com')
        ->and(app(User::class)->where('email', 'case@example.com')->count())->toBe(1);
});

it('fails closed with 401 for a whitespace-only host email', function () {
    actAsHostUser(['email' => '   ']);

    $result = laravelAuthProvider()->login([]);

    expect($result)->toBe(['error' => 'Host user has no email address.', 'code' => 401]);
});

// ---------------------------------------------------------------------------
// Provider contract
// ---------------------------------------------------------------------------

it('reports the session login mode', function () {
    expect(laravelAuthProvider()->loginMode())->toBe('session');
});

it('does not allow registration and refuses register()', function () {
    $provider = laravelAuthProvider();

    expect($provider->canRegister())->toBeFalse();

    expect(fn () => $provider->register([]))
        ->toThrow(RuntimeException::class, 'Self-registration is disabled. Log into the host application.');
});

it('allows login but hides the role selector', function () {
    $provider = laravelAuthProvider();

    expect($provider->canLogin())->toBeTrue()
        ->and($provider->canSelectRole())->toBeFalse()
        ->and($provider->fields())->toBe([]);
});
