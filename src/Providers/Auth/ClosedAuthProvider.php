<?php

namespace Zipavlin\Redline\Providers\Auth;

use Zipavlin\Redline\Contracts\Listable;
use Zipavlin\Redline\Models\Base\User;

/**
 * Closed registration — no self-registration allowed.
 *
 * All users must be created by an admin via the dashboard or
 * the `php artisan redline:user` command. Existing users can log in normally.
 */
class ClosedAuthProvider extends AuthProvider implements Listable
{
    public static function title(): string
    {
        return 'Closed (admin creates all users — no self-registration)';
    }

    public static function position(): int
    {
        return 5;
    }

    public function canRegister(): bool
    {
        return false;
    }

    public function register(array $data): User
    {
        throw new \RuntimeException('Self-registration is disabled. Contact your administrator.');
    }
}
