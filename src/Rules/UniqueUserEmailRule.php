<?php

namespace Zipavlin\Redline\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Zipavlin\Redline\Models\User;

/**
 * Email uniqueness check scoped to the bound User model.
 *
 * Querying the model rather than using the `unique:` rule keeps the check on
 * the model's own storage binding instead of hard-coding a database connection
 * and table. Pass the id of the row being updated to ignore itself.
 */
class UniqueUserEmailRule implements ValidationRule
{
    public function __construct(private readonly ?string $ignoreId = null) {}

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        $query = app(User::class)->newQuery()->where('email', $value);

        if ($this->ignoreId !== null) {
            $query->where('id', '!=', $this->ignoreId);
        }

        if ($query->exists()) {
            $fail('The :attribute has already been taken.');
        }
    }
}
