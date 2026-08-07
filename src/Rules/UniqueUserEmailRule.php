<?php

namespace Zipavlin\Redline\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Zipavlin\Redline\Models\Base\User;

/**
 * Storage-agnostic email uniqueness check.
 *
 * The `unique:` Rule binds to a concrete database connection and table, which
 * breaks under FileStorageProvider (Orbit-backed models). Querying the bound
 * User model instead works for both the Database (SQLite) and File (Orbit)
 * drivers. Pass the id of the row being updated to ignore itself.
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
