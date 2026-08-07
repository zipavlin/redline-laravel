<?php

namespace Zipavlin\Redline\Providers\Auth;

use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Zipavlin\Redline\Enums\Role;
use Zipavlin\Redline\Models\Base\User;
use Zipavlin\Redline\Rules\UniqueUserEmailRule;

/**
 * Controls how users are admitted to Redline.
 *
 * The base class provides sensible defaults for all methods so that
 * simple providers only need to implement canRegister() and register().
 * More complex providers can override rules(), validate(), login(),
 * verify(), etc. as needed.
 */
abstract class AuthProvider
{
    // ── Registration ──────────────────────────────────────────────────────────

    /**
     * Whether self-registration is open at all.
     * Return false for ClosedAuthProvider, CloudAuthProvider.
     */
    public function canRegister(): bool
    {
        return true;
    }

    /**
     * All validation rules for the registration form, including base fields.
     * Providers extend via array_merge(parent::rules(), [...]) to add their own.
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            // Uniqueness is checked against the bound User model (not a DB-bound
            // `unique:` Rule) so it works under both Database and File storage.
            'email' => ['required', 'email', 'max:255', new UniqueUserEmailRule],
            'password' => ['required', 'string', 'min:8'],
            'role' => ['sometimes', 'string', 'in:reporter,developer,admin'],
        ];
    }

    /**
     * Validate registration credentials.
     * Returns an errors array (empty = valid).
     * Default implementation runs rules() through Laravel Validator.
     * Override for complex logic (PIN-to-role resolution, domain checks, etc.).
     */
    public function validate(array $data): array
    {
        $validator = Validator::make($data, $this->rules());

        return $validator->fails() ? $validator->errors()->toArray() : [];
    }

    /**
     * Create a new user record. Called only after validate() passes.
     * Implementations must hash the password before storing.
     *
     * @throws \RuntimeException on failure
     */
    abstract public function register(array $data): User;

    /**
     * Post-registration side effects - send emails, notify admins, etc.
     * Called after register() succeeds.
     */
    public function afterRegister(User $user): void {}

    /**
     * Whether newly registered users must be approved by an admin before they
     * can log in. Providers that create pending accounts return true.
     */
    public function requiresApproval(): bool
    {
        return false;
    }

    /**
     * Whether this provider verifies users by emailing a link (so registration
     * strictly needs a configured mailer). Only DomainAuthProvider does.
     */
    public function requiresEmailVerification(): bool
    {
        return false;
    }

    /**
     * Message shown when registration lands in a pending state. Providers with
     * distinct flows (admin approval, email verification) override with copy
     * that matches their flow.
     */
    public function pendingMessage(): string
    {
        return 'Account created - pending approval or email verification.';
    }

    // ── Login ─────────────────────────────────────────────────────────────────

    /**
     * Whether login is available via this provider.
     * Return false for CloudAuthProvider (login handled externally).
     */
    public function canLogin(): bool
    {
        return true;
    }

    /**
     * Verify login credentials and return user data or an error descriptor.
     *
     * Returns one of:
     *   ['user' => [...]]                                → success
     *   ['status' => 'pending', 'message' => '...', 'code' => 202]  → pending
     *   ['error' => '...', 'code' => 401]               → wrong password
     *   ['error' => '...', 'code' => 404]               → no account found
     */
    public function login(array $data): array
    {
        $user = app(User::class)->where('email', $data['email'] ?? '')->first();

        // Uniform response for "no such account" and "wrong password" so login
        // cannot be used to enumerate registered emails. A dummy hash check keeps
        // the timing of the not-found path comparable to the verification path.
        if (! $user) {
            Hash::check($data['password'] ?? '', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi');

            return ['error' => 'Invalid credentials.', 'code' => 401];
        }

        if (! Hash::check($data['password'] ?? '', $user->password ?? '')) {
            return ['error' => 'Invalid credentials.', 'code' => 401];
        }

        if ($user->status === 'pending') {
            return ['status' => 'pending', 'message' => 'Account not yet approved.', 'code' => 202];
        }

        return ['user' => $user];
    }

    /**
     * Resolve the role a self-registering user receives.
     *
     * Security default: no public registration can mint an admin - admins are
     * created via the console (`redline:user`) or the dashboard. Set
     * REDLINE_AUTH_ALLOW_SELF_ADMIN=true only on trusted networks to restore the
     * "first developer/admin bootstraps the admin account" convenience.
     */
    protected function resolveRole(Role $role): Role
    {
        if (config('redline.auth.allow_self_admin', false)) {
            if (
                in_array($role, [Role::DEVELOPER, Role::ADMIN], true)
                && ! app(User::class)->where('role', Role::ADMIN)->exists()
            ) {
                return Role::ADMIN;
            }

            return $role;
        }

        return $role === Role::ADMIN ? Role::REPORTER : $role;
    }

    // ── Verification (optional multi-step flows) ───────────────────────────────

    /**
     * Handle token-based verification (e.g. email click).
     * Returns ['user' => [...]] on success, ['error' => '...'] on failure.
     * Default: no-op (providers that need verification must override).
     */
    public function verify(array $data): array
    {
        return [];
    }

    /**
     * Post-verification side effects - send welcome emails, log events, etc.
     * Called after verify() returns a user successfully.
     */
    public function afterVerify(User $user): void {}

    // ── Widget configuration ───────────────────────────────────────────────────

    /**
     * Provider-specific extra fields the widget should render
     * beyond the base set (name, email, password).
     *
     * Each descriptor: ['name', 'type', 'label', 'placeholder', 'when' => [...]]
     * type: 'text' | 'password' | 'email' | 'hidden'
     * when: ['field' => 'role', 'in' => ['developer', 'admin']] - conditional display
     */
    public function fields(): array
    {
        return [];
    }

    /**
     * Whether the widget should show the role radio buttons.
     * Return false when the provider determines role itself (e.g. PinAuthProvider).
     */
    public function canSelectRole(): bool
    {
        return true;
    }
}
