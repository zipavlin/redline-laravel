<?php

namespace Zipavlin\Redline\Providers\Auth;

use Illuminate\Support\Facades\Hash;
use Zipavlin\Redline\Contracts\Listable;
use Zipavlin\Redline\Enums\Role;
use Zipavlin\Redline\Models\User;

/**
 * Approval-required registration - anyone may submit a registration request,
 * but the account remains pending until an admin approves it and assigns a role
 * via the dashboard.
 *
 * Pending users cannot log in until approved.
 */
class ApprovalAuthProvider extends AuthProvider implements Listable
{
    public static function title(): string
    {
        return 'Approval (self-register → pending → admin approves)';
    }

    public static function position(): int
    {
        return 4;
    }

    public function requiresApproval(): bool
    {
        return true;
    }

    public function pendingMessage(): string
    {
        return 'Your registration was created and needs to be approved by admin user.';
    }

    public function register(array $data): User
    {
        return app(User::class)->create([
            'name' => $data['name'],
            'email' => $data['email'],
            'password' => Hash::make($data['password']),
            'role' => Role::REPORTER,
            'status' => 'pending',
        ]);
    }
}
