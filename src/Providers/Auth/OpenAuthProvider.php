<?php

namespace Zipavlin\Redline\Providers\Auth;

use Illuminate\Support\Facades\Hash;
use Zipavlin\Redline\Contracts\Listable;
use Zipavlin\Redline\Enums\Role;
use Zipavlin\Redline\Models\Base\User;

/**
 * Open registration - anyone can self-register and freely choose any role.
 *
 * The first user to claim developer or admin is automatically promoted to admin
 * if no admin exists yet.
 *
 * Suitable for internal staging environments where all users are trusted.
 */
class OpenAuthProvider extends AuthProvider implements Listable
{
    public static function title(): string
    {
        return 'Open (anyone can register, free role selection)';
    }

    public static function position(): int
    {
        return 1;
    }

    public function canRegister(): bool
    {
        return true;
    }

    public function register(array $data): User
    {
        $role = $this->resolveRole(Role::tryFrom($data['role'] ?? '') ?? Role::REPORTER);

        return app(User::class)->create([
            'name' => $data['name'],
            'email' => $data['email'],
            'password' => Hash::make($data['password']),
            'role' => $role,
            'status' => 'approved',
        ]);
    }
}
