<?php

use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\URL;
use Zipavlin\Redline\Mail\NewReportMail;
use Zipavlin\Redline\Mail\PasswordResetMail;
use Zipavlin\Redline\Mail\VerifyEmailMail;
use Zipavlin\Redline\Models\Base\User;
use Zipavlin\Redline\Providers\Auth\AuthProvider;
use Zipavlin\Redline\Providers\Auth\DomainAuthProvider;

beforeEach(function () {
    ensureRedlineDirectories();
    // mailConfigured() is derived from mail.default; pretend a real mailer is set.
    config(['mail.default' => 'smtp']);
});

it('emails a verification link on domain registration', function () {
    Mail::fake();
    $this->app->singleton(AuthProvider::class, DomainAuthProvider::class);
    config(['redline.auth.domains' => 'example.com']);

    $this->postJson('/redline/api/auth/register', [
        'name' => 'Jane', 'email' => 'jane@example.com', 'password' => 'secret123',
    ])->assertStatus(202);

    Mail::assertSent(VerifyEmailMail::class);
});

it('does NOT email when no mailer is configured (gated on mailConfigured)', function () {
    config(['mail.default' => 'array']); // not a real transport
    Mail::fake();
    $this->app->singleton(AuthProvider::class, DomainAuthProvider::class);
    config(['redline.auth.domains' => 'example.com']);

    $this->postJson('/redline/api/auth/register', [
        'name' => 'Jane', 'email' => 'jane@example.com', 'password' => 'secret123',
    ])->assertStatus(202);

    Mail::assertNothingSent();
});

it('notifies developers/admins when a report is filed', function () {
    Mail::fake();
    makeUser('admin'); // a recipient must exist

    $this->postJson('/redline/api/report', makeReportData(), authHeaders('reporter'))
        ->assertStatus(201);

    Mail::assertSent(NewReportMail::class);
});

it('emails a signed reset link on forgot-password (and stays uniform for unknown emails)', function () {
    Mail::fake();
    $user = makeUser('reporter');

    // Unknown email — still 200, no mail.
    $this->postJson('/redline/api/auth/forgot-password', ['email' => 'ghost@example.com'])->assertStatus(200);
    Mail::assertNothingSent();

    // Known email — 200 + a reset mail.
    $this->postJson('/redline/api/auth/forgot-password', ['email' => $user['email']])->assertStatus(200);
    Mail::assertSent(PasswordResetMail::class);
});

it('resets the password via a valid signed link and revokes old tokens', function () {
    $user = app(User::class)->create([
        'name' => 'Reset Me', 'email' => 'reset@example.com',
        'password' => Hash::make('oldpassword'), 'role' => 'reporter', 'status' => 'approved',
    ]);
    $oldToken = ['Authorization' => 'Bearer '.makeAuthToken($user->toArray())];
    $this->getJson('/redline/api/auth/me', $oldToken)->assertStatus(200);

    $signed = URL::temporarySignedRoute('redline.auth.reset-password', now()->addHour(), ['email' => 'reset@example.com']);
    // The SPA re-POSTs the signed query verbatim.
    $query = parse_url($signed, PHP_URL_QUERY);

    $this->postJson('/redline/api/auth/reset-password?'.$query, ['password' => 'brandnew123'])
        ->assertStatus(200);

    // Old token is now revoked (token_version bumped).
    $this->getJson('/redline/api/auth/me', $oldToken)->assertStatus(401);

    // The new password works.
    expect(Hash::check('brandnew123', app(User::class)->find($user->id)->password))->toBeTrue();
});

it('rejects reset-password with a tampered signature', function () {
    app(User::class)->create([
        'name' => 'Reset Me', 'email' => 'reset@example.com',
        'password' => Hash::make('oldpassword'), 'role' => 'reporter', 'status' => 'approved',
    ]);

    $this->postJson('/redline/api/auth/reset-password?email=reset@example.com&signature=deadbeef', ['password' => 'brandnew123'])
        ->assertStatus(403);
});
