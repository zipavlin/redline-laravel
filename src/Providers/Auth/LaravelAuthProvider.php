<?php

namespace Zipavlin\Redline\Providers\Auth;

use Illuminate\Support\Facades\Auth;
use Zipavlin\Redline\Contracts\Listable;
use Zipavlin\Redline\Enums\Role;
use Zipavlin\Redline\Models\Base\User;

/**
 * Host-application session auth - the Laravel user IS the redline identity.
 *
 * Pair this with LaravelGuardProvider: the host app authenticates the user, the
 * widget exchanges that session for a redline JWT, and redline provisions a
 * matching user record on first sight.
 *
 * WARNING: the email is trusted as proof of identity. Anyone who can obtain a host
 * account with a given email inherits the matching redline identity, so do NOT pair
 * this provider with host registration that is open to untrusted users.
 */
class LaravelAuthProvider extends AuthProvider implements Listable
{
    public static function title(): string
    {
        return 'Laravel (host application session; no redline forms)';
    }

    public static function position(): int
    {
        return 6;
    }

    public function canRegister(): bool
    {
        return false;
    }

    public function register(array $data): User
    {
        throw new \RuntimeException('Self-registration is disabled. Log into the host application.');
    }

    public function canLogin(): bool
    {
        return true;
    }

    public function canSelectRole(): bool
    {
        return false;
    }

    public function loginMode(): string
    {
        return 'session';
    }

    /**
     * The host app guard to resolve the user from. Override in a subclass to use a
     * named guard without touching login().
     *
     * NOTE: overriding this on only one of LaravelGuardProvider/LaravelAuthProvider
     * splits widget visibility from exchange identity - keep the two in sync.
     */
    protected function guardName(): ?string
    {
        return null;
    }

    /**
     * Exchange the host application session for a redline user.
     *
     * The Laravel user's email is the only join key to a redline identity, so a
     * host user without one fails closed rather than creating an unjoinable record.
     * An existing redline user is returned untouched - no name sync, no role change.
     *
     * $data is ignored: in session mode the host session is the only credential.
     */
    public function login(array $data): array
    {
        $laravel = Auth::guard($this->guardName())->user();

        if ($laravel === null) {
            return ['error' => 'Not authenticated with the host application.', 'code' => 401];
        }

        // Joined case-insensitively so mixed-case/whitespace rows still match.
        $email = mb_strtolower(trim((string) $laravel->email));

        if ($email === '') {
            return ['error' => 'Host user has no email address.', 'code' => 401];
        }

        return ['user' => $this->findByEmail($email) ?? $this->provisionUser($laravel, $email)];
    }

    /**
     * Look up the redline user for a normalized email.
     *
     * The exact-match query hits first; the fallback fetch-and-compare is portable
     * across SQLite's byte-exact comparison AND Orbit's schemaless JSON driver (no
     * whereRaw, no reliance on LIKE). Redline user counts are small, so a bounded
     * fetch is acceptable.
     */
    private function findByEmail(string $email): ?User
    {
        $exact = app(User::class)->where('email', $email)->first();

        if ($exact !== null) {
            return $exact;
        }

        return app(User::class)->get()->first(
            fn (User $candidate) => mb_strtolower(trim((string) $candidate->email)) === $email
        );
    }

    /**
     * First sighting of a Laravel user: create the matching redline reporter.
     * The role is fixed (never resolved from config) so the host session can only
     * ever mint a reporter - developers/admins are assigned in redline itself.
     *
     * createOrFirst absorbs the race between two concurrent first exchanges: the
     * unique index makes the loser refetch instead of throwing. Under Orbit (no
     * unique index) it degrades to a plain create, which is the existing behavior.
     */
    protected function provisionUser(object $laravel, string $email): User
    {
        return app(User::class)->createOrFirst(
            ['email' => $email],
            [
                'name' => ($laravel->name ?? null) ?: $email,
                'email' => $email,
                'password' => null,
                'role' => Role::REPORTER,
                'status' => 'approved',
            ]
        );
    }
}
