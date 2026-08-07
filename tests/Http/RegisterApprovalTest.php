<?php

use Zipavlin\Redline\Models\Base\User;
use Zipavlin\Redline\Providers\Auth\ApprovalAuthProvider;
use Zipavlin\Redline\Providers\Auth\AuthProvider;
use Zipavlin\Redline\Providers\Auth\OpenAuthProvider;

it('sets status pending when the active provider requires approval', function () {
    $this->app->singleton(AuthProvider::class, ApprovalAuthProvider::class);

    $this->postJson('/redline/api/auth/register', [
        'name' => 'Alice',
        'email' => 'alice@example.com',
        'password' => 'secret123',
        'role' => 'reporter',
    ])->assertStatus(202);

    $user = app(User::class)->where('email', 'alice@example.com')->first();

    expect($user)->not->toBeNull()
        ->and($user->status)->toBe('pending')
        ->and($user->isPending)->toBeTrue();
});

it('sets status approved when the active provider does not require approval', function () {
    $this->app->singleton(AuthProvider::class, OpenAuthProvider::class);

    $this->postJson('/redline/api/auth/register', [
        'name' => 'Bob',
        'email' => 'bob@example.com',
        'password' => 'secret123',
        'role' => 'reporter',
    ])->assertStatus(200);

    $user = app(User::class)->where('email', 'bob@example.com')->first();

    expect($user->status)->toBe('approved');
});
