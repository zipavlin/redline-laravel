<?php

use Zipavlin\Redline\Enums\FieldType;
use Zipavlin\Redline\Providers\Auth\PinAuthProvider;

beforeEach(function () {
    $this->auth = new PinAuthProvider;
});

// ---------------------------------------------------------------------------
// validate() — PIN matching
// ---------------------------------------------------------------------------

it('returns no errors when the reporter PIN matches', function () {
    config(['redline.auth.pin.reporter' => 'rep-pin', 'redline.auth.pin.developer' => null]);

    expect($this->auth->validate([
        'name' => 'Jane', 'email' => 'jane@example.com', 'password' => 'secret123', 'pin' => 'rep-pin',
    ]))->toBeEmpty();
});

it('returns no errors when the developer PIN matches', function () {
    config(['redline.auth.pin.developer' => 'dev-pin', 'redline.auth.pin.reporter' => null]);

    expect($this->auth->validate([
        'name' => 'Jane', 'email' => 'jane@example.com', 'password' => 'secret123', 'pin' => 'dev-pin',
    ]))->toBeEmpty();
});

it('returns a pin error when neither PIN matches', function () {
    config(['redline.auth.pin.reporter' => 'rep-pin', 'redline.auth.pin.developer' => 'dev-pin']);

    $errors = $this->auth->validate([
        'name' => 'Jane', 'email' => 'jane@example.com', 'password' => 'secret123', 'pin' => 'wrong',
    ]);

    expect($errors)->toHaveKey('pin');
});

it('returns a pin error when no PINs are configured', function () {
    config(['redline.auth.pin.reporter' => null, 'redline.auth.pin.developer' => null]);

    $errors = $this->auth->validate([
        'name' => 'Jane', 'email' => 'jane@example.com', 'password' => 'secret123', 'pin' => 'anything',
    ]);

    expect($errors)->toHaveKey('pin');
});

it('is case-sensitive', function () {
    config(['redline.auth.pin.developer' => 'Secret', 'redline.auth.pin.reporter' => null]);

    expect($this->auth->validate([
        'name' => 'Jane', 'email' => 'jane@example.com', 'password' => 'secret123', 'pin' => 'secret',
    ]))->toHaveKey('pin')
        ->and($this->auth->validate([
            'name' => 'Jane', 'email' => 'jane@example.com', 'password' => 'secret123', 'pin' => 'Secret',
        ]))->toBeEmpty();
});

// ---------------------------------------------------------------------------
// canSelectRole() — always false for PinAuthProvider
// ---------------------------------------------------------------------------

it('canSelectRole returns false', function () {
    expect($this->auth->canSelectRole())->toBeFalse();
});

// ---------------------------------------------------------------------------
// fields() — declares the PIN field
// ---------------------------------------------------------------------------

it('declares a single pin field', function () {
    $fields = $this->auth->fields();

    expect($fields)->toHaveCount(1)
        ->and($fields[0]['name'])->toBe('pin')
        ->and($fields[0]['type'])->toBe(FieldType::PIN);
});
