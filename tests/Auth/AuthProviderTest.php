<?php

use Zipavlin\Redline\Providers\Auth\OpenAuthProvider;

// Contract tests for the base AuthProvider login path, exercised through the
// simplest concrete provider (OpenAuthProvider).

it('rejects an empty email with a 422 error', function () {
    $result = (new OpenAuthProvider)->login(['email' => '', 'password' => 'x']);

    expect($result)->toHaveKey('error')
        ->and($result['code'])->toBe(422);
});

it('rejects a malformed email with a 422 error', function () {
    $result = (new OpenAuthProvider)->login(['email' => 'not-an-email', 'password' => 'x']);

    expect($result)->toHaveKey('error')
        ->and($result['code'])->toBe(422);
});

it('rejects a missing password with a 422 error', function () {
    $result = (new OpenAuthProvider)->login(['email' => 'a@b.com']);

    expect($result)->toHaveKey('error')
        ->and($result['code'])->toBe(422);
});

it('defaults to credentials login mode', function () {
    expect((new OpenAuthProvider)->loginMode())->toBe('credentials');
});
