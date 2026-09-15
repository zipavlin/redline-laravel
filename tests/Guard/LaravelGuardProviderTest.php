<?php

use Illuminate\Auth\GenericUser;
use Illuminate\Http\Request;
use Zipavlin\Redline\Providers\Guard\LaravelGuardProvider;

function hostUser(array $attributes = []): GenericUser
{
    return new GenericUser(array_merge([
        'id' => 1,
        'name' => 'Host User',
        'email' => 'host@example.com',
    ], $attributes));
}

it('is inactive when no host application user is logged in', function () {
    expect((new LaravelGuardProvider)->isActive(Request::create('/')))->toBeFalse();
});

it('is active when the host application user is logged in', function () {
    $this->actingAs(hostUser());

    expect((new LaravelGuardProvider)->isActive(Request::create('/')))->toBeTrue();
});

it('always wires up middleware at boot, regardless of auth state', function () {
    $guard = new LaravelGuardProvider;

    // Boot runs before any middleware, so the session is never available here -
    // the guard must answer true even with no host user.
    expect($guard->shouldLoadMiddleware(Request::create('/')))->toBeTrue();

    $this->actingAs(hostUser());

    expect($guard->shouldLoadMiddleware(Request::create('/')))->toBeTrue();
});
