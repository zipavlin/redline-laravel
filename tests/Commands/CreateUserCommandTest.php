<?php

use Illuminate\Support\Facades\Hash;
use Zipavlin\Redline\Enums\Role;
use Zipavlin\Redline\Models\Database\User as DatabaseUser;
use Zipavlin\Redline\Providers\Storage\FileStorageProvider;

it('creates a Database user with a hashed password and role', function () {
    $this->artisan('redline:user')
        ->expectsQuestion('Name', 'Zig')
        ->expectsQuestion('Email', 'zig@example.com')
        ->expectsQuestion('Password (min 6 characters)', 'secret123')
        ->expectsQuestion('Role', 'admin')
        ->expectsOutputToContain('User Zig (zig@example.com) created')
        ->assertExitCode(0);

    $user = app(DatabaseUser::class)->where('email', 'zig@example.com')->first();

    expect($user)->not->toBeNull()
        ->and(Hash::check('secret123', $user->password))->toBeTrue()
        ->and($user->role)->toBe(Role::ADMIN);
});

it('fails cleanly when File storage is configured without orbit', function () {
    if (trait_exists('Orbit\Concerns\Orbital')) {
        $this->markTestSkipped('orbit is installed - the without-orbit failure path is untestable here.');
    }

    config(['redline.storage.provider' => FileStorageProvider::class]);

    $this->artisan('redline:user')
        ->expectsOutputToContain('requires ryangjchandler/orbit')
        ->assertExitCode(1);
});
