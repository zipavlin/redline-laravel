<?php

namespace Zipavlin\Redline\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * Validates that an email address belongs to one of the allowed domains.
 */
class AllowedDomainRule implements ValidationRule
{
    public function __construct(private readonly array $allowedDomains) {}

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        $email = (string) $value;
        $atPos = strrpos($email, '@');

        if ($atPos === false) {
            $fail('The :attribute must be a valid email address.');

            return;
        }

        $domain = substr($email, $atPos + 1);

        if (! in_array($domain, $this->allowedDomains, true)) {
            $fail('The :attribute domain is not allowed to register.');
        }
    }
}
