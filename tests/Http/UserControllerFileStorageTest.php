<?php

use Zipavlin\Redline\Providers\Storage\FileStorageProvider;

/**
 * UserController email-uniqueness under FileStorageProvider. The `unique:`
 * Rule is bound to a concrete connection/table and breaks under Orbit (there is
 * no `redline.redline_users` table); the storage-agnostic UniqueUserEmailRule
 * must behave identically here.
 *
 * Skipped unless ryangjchandler/orbit is installed - see
 * tests/Models/FileDriverGrammarTest.php for the same guard, do not remove it.
 */
beforeEach(function () {
    if (! trait_exists('Orbit\Concerns\Orbital')) {
        $this->markTestSkipped('ryangjchandler/orbit is not installed - the File storage driver is untestable here.');
    }

    cleanJsonStorage();
    ensureRedlineDirectories();
    (new FileStorageProvider)->boot();

    $this->admin = makeUser('admin');
    $this->adminHeaders = ['Authorization' => 'Bearer '.makeAuthToken($this->admin)];
});

it('rejects a duplicate email on store under File storage', function () {
    $user = makeUser('reporter');

    $this->postJson('/redline/api/user', [
        'name' => 'Dup', 'email' => $user['email'], 'role' => 'reporter', 'password' => 'secret123',
    ], $this->adminHeaders)->assertStatus(422);
});

it('rejects a duplicate email on update under File storage', function () {
    $user = makeUser('reporter');
    $other = makeUser('reporter');

    $this->patchJson("/redline/api/user/{$user['id']}", [
        'email' => $other['email'],
    ], $this->adminHeaders)->assertStatus(422);
});

it('allows keeping the same email on update under File storage', function () {
    $user = makeUser('reporter');

    $this->patchJson("/redline/api/user/{$user['id']}", [
        'email' => $user['email'],
    ], $this->adminHeaders)->assertStatus(200);
});
