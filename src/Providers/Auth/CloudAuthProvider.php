<?php

namespace Zipavlin\Redline\Providers\Auth;

use Zipavlin\Redline\Models\Base\User;

/**
 * Cloud auth — all authentication and user management is handled by the
 * remote Redline cloud service. JWTs are issued by the cloud and verified
 * locally using the cloud's public key / shared secret.
 *
 * This provider is used exclusively with CloudStorageProvider.
 * Neither self-registration nor local login apply.
 */
class CloudAuthProvider extends AuthProvider
{
    public function canRegister(): bool
    {
        return false;
    }

    public function canLogin(): bool
    {
        return false;
    }

    public function register(array $data): User
    {
        throw new \RuntimeException('CloudAuthProvider: user management is handled by the remote service.');
    }

    public function login(array $data): array
    {
        throw new \RuntimeException('CloudAuthProvider: login is handled by the remote service.');
    }
}
