<?php

namespace Zipavlin\Redline\Providers\Auth;

use Illuminate\Support\Facades\Hash;
use Zipavlin\Redline\Contracts\Listable;
use Zipavlin\Redline\Enums\FieldType;
use Zipavlin\Redline\Enums\Role;
use Zipavlin\Redline\Models\Base\User;
use Zipavlin\Redline\Transports\Field;

/**
 * PIN-gated registration - anyone can register but role is determined by PIN.
 *
 * A reporter PIN grants the reporter role; a developer PIN grants developer.
 * Configure via REDLINE_AUTH_PIN_REPORTER and REDLINE_AUTH_PIN_DEVELOPER.
 *
 * Because role comes from the PIN, the widget hides the role selector.
 * The first developer to register is auto-promoted to admin if none exists.
 */
class PinAuthProvider extends AuthProvider implements Listable
{
    public static function title(): string
    {
        return 'PIN (secret code required to claim developer role)';
    }

    public static function position(): int
    {
        return 2;
    }

    public function canRegister(): bool
    {
        return true;
    }

    public function canSelectRole(): bool
    {
        return false;
    }

    public function fields(): array
    {
        return [
            Field::make(
                name: 'pin',
                type: FieldType::PIN,
                label: 'PIN',
                placeholder: '#',
                extra: [
                    'length' => max(
                        strlen((string) config('redline.auth.pin.reporter', '')),
                        strlen((string) config('redline.auth.pin.developer', '')),
                    ),
                ],
            ),
        ];
    }

    public function rules(): array
    {
        return array_merge(parent::rules(), [
            // Bare `required` so both string and numeric PINs are accepted;
            // both are cast to string before the constant-time comparison.
            'pin' => ['required'],
        ]);
    }

    public function validate(array $data): array
    {
        $errors = parent::validate($data);
        if (! empty($errors)) {
            return $errors;
        }

        $pin = $this->normalizePin($data['pin']);
        $reporterPin = (string) config('redline.auth.pin.reporter', '');
        $developerPin = (string) config('redline.auth.pin.developer', '');

        if ($reporterPin && hash_equals($reporterPin, $pin)) {
            return [];
        }
        if ($developerPin && hash_equals($developerPin, $pin)) {
            return [];
        }

        return ['pin' => ['Incorrect PIN.']];
    }

    public function register(array $data): User
    {
        $pin = $this->normalizePin($data['pin']);
        $developerPin = (string) config('redline.auth.pin.developer', '');

        $role = ($developerPin && hash_equals($developerPin, $pin)) ? Role::DEVELOPER : Role::REPORTER;
        $role = $this->resolveRole($role);

        return app(User::class)->create([
            'name' => $data['name'],
            'email' => $data['email'],
            'password' => Hash::make($data['password']),
            'role' => $role,
            'status' => 'approved',
        ]);
    }

    private function normalizePin(string|array|null $pin): string
    {
        if (empty($pin)) {
            return '';
        }

        if (is_array($pin)) {
            return implode('', $pin);
        }

        return (string) ($pin ?? '');
    }
}
