<?php

namespace Zipavlin\Redline\Providers\Auth;

use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\URL;
use Zipavlin\Redline\Contracts\Listable;
use Zipavlin\Redline\Enums\Role;
use Zipavlin\Redline\Mail\VerifyEmailMail;
use Zipavlin\Redline\Models\Base\User;
use Zipavlin\Redline\RedlineServiceProvider;
use Zipavlin\Redline\Rules\AllowedDomainRule;

/**
 * Domain-restricted registration with email verification.
 *
 * Only email addresses matching the configured domain(s) may register, and
 * the account is held pending until the user clicks the verification link
 * sent to their email address.
 *
 * Configure via REDLINE_ALLOWED_DOMAIN (single) or REDLINE_ALLOWED_DOMAINS
 * (comma-separated list).
 */
class DomainAuthProvider extends AuthProvider implements Listable
{
    public static function title(): string
    {
        return 'Domain (restrict registration to allowed email domains)';
    }

    public static function position(): int
    {
        return 3;
    }

    public function requiresEmailVerification(): bool
    {
        return true;
    }

    public function pendingMessage(): string
    {
        return 'Your registration was created. Please follow instructions in confirmation email.';
    }

    public function rules(): array
    {
        $domains = str(config('redline.auth.domains', ''))
            ->explode(',')
            ->map(fn ($d) => trim($d))
            ->unique()
            ->toArray();

        $rules = parent::rules();

        // Extend the email rule to also check the allowed domain(s).
        $rules['email'] = array_merge($rules['email'], [new AllowedDomainRule($domains)]);

        return $rules;
    }

    public function register(array $data): User
    {
        $role = $this->resolveRole(Role::tryFrom($data['role'] ?? '') ?? Role::REPORTER);

        return app(User::class)->create([
            'name' => $data['name'],
            'email' => $data['email'],
            'password' => Hash::make($data['password']),
            'role' => $role,
            'status' => 'pending',
        ]);
    }

    public function afterRegister(User $user): void
    {
        // Signed verification link, valid 24h; the signature proves email ownership
        // and the verify() route validates it. No-op if no mailer is configured.
        $url = URL::temporarySignedRoute(
            name: 'redline.auth.verify',
            expiration: now()->addHours(24),
            parameters: ['email' => $user->email],
        );

        RedlineServiceProvider::mail($user->email, new VerifyEmailMail(
            name: $user->name,
            verifyUrl: $url,
            appUrl: config('app.url'),
        ));
    }

    public function verify(array $data): array
    {
        $email = $data['email'] ?? '';

        $user = app(User::class)->where('email', $email)->first();

        if (! $user) {
            return ['error' => 'Invalid or expired verification link.'];
        }

        if ($user->status === 'approved') {
            // Already verified - treat as success so the user gets a token.
            return ['user' => $user];
        }

        $user->update(['status' => 'approved']);

        return ['user' => $user->fresh()];
    }
}
