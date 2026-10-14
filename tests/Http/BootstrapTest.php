<?php

use Zipavlin\Redline\Enums\Feature;
use Zipavlin\Redline\Providers\Auth\ApprovalAuthProvider;
use Zipavlin\Redline\Providers\Auth\AuthProvider;
use Zipavlin\Redline\Providers\Auth\DomainAuthProvider;
use Zipavlin\Redline\Providers\Auth\OpenAuthProvider;
use Zipavlin\Redline\Providers\Auth\PinAuthProvider;

beforeEach(function () {
    // The storage boot in TestCase handles the in-memory DB - no
    // ensureRedlineDirectories() needed here (see AuthControllerTest).
    $this->app->singleton(AuthProvider::class, OpenAuthProvider::class);
});

// ---------------------------------------------------------------------------
// Public-ness
// ---------------------------------------------------------------------------

it('is public - returns 200 with no auth and no cookies', function () {
    $this->getJson('/redline/api/bootstrap')
        ->assertStatus(200)
        ->assertJsonPath('version', 2);
});

// ---------------------------------------------------------------------------
// Features
// ---------------------------------------------------------------------------

it('exposes all four features by default', function () {
    $this->getJson('/redline/api/bootstrap')
        ->assertStatus(200)
        ->assertJsonPath('features', ['screenshots', 'videos', 'files', 'comments']);
});

it('respects redline.disabled', function () {
    config(['redline.disabled' => [Feature::VIDEOS, Feature::FILES]]);

    $this->getJson('/redline/api/bootstrap')
        ->assertStatus(200)
        ->assertJsonPath('features', ['screenshots', 'comments']);
});

// ---------------------------------------------------------------------------
// Capabilities
// ---------------------------------------------------------------------------

it('reports guest_reporting false by default and true when enabled', function () {
    $this->getJson('/redline/api/bootstrap')
        ->assertStatus(200)
        ->assertJsonPath('capabilities.guest_reporting', false);

    config(['redline.auth.guest' => true]);

    $this->getJson('/redline/api/bootstrap')
        ->assertStatus(200)
        ->assertJsonPath('capabilities.guest_reporting', true);
});

it('exposes OpenAuthProvider capabilities', function () {
    $this->getJson('/redline/api/bootstrap')
        ->assertStatus(200)
        ->assertJsonPath('capabilities.auth.can_login', true)
        ->assertJsonPath('capabilities.auth.login_mode', 'credentials')
        ->assertJsonPath('capabilities.auth.can_register', true)
        ->assertJsonPath('capabilities.auth.fields', [])
        ->assertJsonPath('capabilities.auth.can_select_role', true)
        ->assertJsonPath('capabilities.auth.requires_approval', false)
        ->assertJsonPath('capabilities.auth.requires_email_verification', false);
});

it('exposes PinAuthProvider capabilities (no role selector + pin field)', function () {
    $this->app->singleton(AuthProvider::class, PinAuthProvider::class);
    config(['redline.auth.pin.developer' => 'secret', 'redline.auth.pin.reporter' => null]);

    $this->getJson('/redline/api/bootstrap')
        ->assertStatus(200)
        ->assertJsonPath('capabilities.auth.can_select_role', false)
        ->assertJsonPath('capabilities.auth.fields.0.name', 'pin');
});

it('exposes ApprovalAuthProvider requires_approval', function () {
    $this->app->singleton(AuthProvider::class, ApprovalAuthProvider::class);

    $this->getJson('/redline/api/bootstrap')
        ->assertStatus(200)
        ->assertJsonPath('capabilities.auth.requires_approval', true);
});

it('exposes DomainAuthProvider requires_email_verification', function () {
    $this->app->singleton(AuthProvider::class, DomainAuthProvider::class);

    $this->getJson('/redline/api/bootstrap')
        ->assertStatus(200)
        ->assertJsonPath('capabilities.auth.requires_email_verification', true);
});

// ---------------------------------------------------------------------------
// Mail
// ---------------------------------------------------------------------------

it('reports mail.configured false for log/array/null mailers and true for a real one', function () {
    foreach (['log', 'array', 'null'] as $mailer) {
        config(['mail.default' => $mailer]);

        $this->getJson('/redline/api/bootstrap')
            ->assertStatus(200)
            ->assertJsonPath('capabilities.mail.configured', false);
    }

    config(['mail.default' => 'smtp']);

    $this->getJson('/redline/api/bootstrap')
        ->assertStatus(200)
        ->assertJsonPath('capabilities.mail.configured', true);
});

// ---------------------------------------------------------------------------
// CSRF names
// ---------------------------------------------------------------------------

it('reports the configured csrf cookie and header names', function () {
    $this->getJson('/redline/api/bootstrap')
        ->assertStatus(200)
        ->assertJsonPath('csrf.cookie', 'redline_csrf')
        ->assertJsonPath('csrf.header', 'X-Redline-CSRF');

    config(['redline.auth.csrf_cookie' => 'my_csrf', 'redline.auth.csrf_header' => 'X-My-CSRF']);

    $this->getJson('/redline/api/bootstrap')
        ->assertStatus(200)
        ->assertJsonPath('csrf.cookie', 'my_csrf')
        ->assertJsonPath('csrf.header', 'X-My-CSRF');
});

// ---------------------------------------------------------------------------
// Security
// ---------------------------------------------------------------------------

it('does not leak secrets', function () {
    $body = $this->get('/redline/api/bootstrap')->getContent();

    expect($body)->not->toContain('password')
        ->and($body)->not->toContain('app.key');
});
