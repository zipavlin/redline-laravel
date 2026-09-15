<?php

use Illuminate\Auth\GenericUser;
use Zipavlin\Redline\Models\Base\User;
use Zipavlin\Redline\Providers\Auth\LaravelAuthProvider;
use Zipavlin\Redline\Providers\Storage\FileStorageProvider;

/**
 * LaravelAuthProvider + FileStorageProvider: the email join must be
 * case/whitespace-insensitive under Orbit's schemaless JSON driver too, where
 * SQLite's byte-exact string comparison would otherwise miss a mixed-case row.
 *
 * Skipped unless ryangjchandler/orbit is installed - see
 * tests/Http/DomainAuthFileStorageTest.php for the same guard, do not remove it.
 */
beforeEach(function () {
    if (! trait_exists('Orbit\Concerns\Orbital')) {
        $this->markTestSkipped('ryangjchandler/orbit is not installed - the File storage driver is untestable here.');
    }

    cleanJsonStorage();
    ensureRedlineDirectories();
    (new FileStorageProvider)->boot();
});

function actAsFileStorageHostUser(string $email, string $name = 'Orbit Host'): GenericUser
{
    $user = new GenericUser(['id' => 1, 'name' => $name, 'email' => $email]);

    test()->actingAs($user);

    return $user;
}

it('stores a normalized email when provisioning under File storage', function () {
    actAsFileStorageHostUser('Orbit@Example.COM');

    $user = (new LaravelAuthProvider)->login([])['user'];

    expect($user)->toBeInstanceOf(User::class)
        ->and($user->email)->toBe('orbit@example.com')
        ->and(app(User::class)->count())->toBe(1);
});

it('matches a pre-existing mixed-case Orbit row against a lowercase host email', function () {
    $existing = app(User::class)->create([
        'name' => 'Orbit Mixed',
        'email' => 'Orbit.Mixed@Example.com',
        'role' => 'developer',
        'status' => 'approved',
    ]);

    actAsFileStorageHostUser('orbit.mixed@example.com');

    $user = (new LaravelAuthProvider)->login([])['user'];

    expect($user->id)->toBe($existing->id)
        ->and(app(User::class)->count())->toBe(1);
});
