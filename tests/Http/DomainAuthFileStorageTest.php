<?php

use Illuminate\Support\Facades\Mail;
use Zipavlin\Redline\Mail\VerifyEmailMail;
use Zipavlin\Redline\Providers\Auth\AuthProvider;
use Zipavlin\Redline\Providers\Auth\DomainAuthProvider;
use Zipavlin\Redline\Providers\Storage\FileStorageProvider;

/**
 * DomainAuthProvider + FileStorageProvider: the verification email must reach
 * the mailer under File (Orbit) storage exactly as it does under Database
 * storage. The send is storage-agnostic - this guards against regressions in
 * the Orbit model path (attribute access, file-write timing, bindings) and
 * pins down that the "no verification email" symptom under File storage is
 * the silent mail failure (Bug 4), not a File-specific send bug.
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

    config(['mail.default' => 'smtp']);
    Mail::fake();
    $this->app->singleton(AuthProvider::class, DomainAuthProvider::class);
    config(['redline.auth.domains' => 'example.com']);
});

it('emails the verification link on domain registration under File storage', function () {
    $this->postJson('/redline/api/auth/register', [
        'name' => 'Jane',
        'email' => 'jane@example.com',
        'password' => 'secret123',
    ])->assertStatus(202);

    Mail::assertSent(VerifyEmailMail::class);
});
