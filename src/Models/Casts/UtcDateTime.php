<?php

namespace Zipavlin\Redline\Models\Casts;

use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * A `datetime` cast for columns that store a UTC wall clock with no offset.
 *
 * Laravel's built-in date cast parses a naive database string with
 * `Carbon::createFromFormat($format, $value)` and no timezone, which resolves it
 * in the host's `app.timezone`. On a non-UTC host that reads the stored UTC clock
 * as local time and serializes a shifted instant. This cast pins both directions
 * to UTC, so the wire value is the true instant the column represents whatever
 * the host's timezone is.
 *
 * @implements CastsAttributes<?Carbon, \DateTimeInterface|string|null>
 */
class UtcDateTime implements CastsAttributes
{
    /**
     * Read the stored naive-UTC string as the UTC instant it represents.
     *
     * The explicit UTC timezone is the whole point: without it Carbon resolves
     * the naive string in the (possibly non-UTC) PHP default timezone.
     */
    public function get(Model $model, string $key, mixed $value, array $attributes): ?Carbon
    {
        if ($value === null || $value === '') {
            return null;
        }

        return Carbon::createFromFormat($model->getDateFormat(), $value, 'UTC');
    }

    /**
     * Format any incoming instant for storage after normalizing it to UTC, so
     * assigning a non-UTC Carbon directly to the model is safe too. A naive
     * string is read AS UTC (the column's contract); without the explicit
     * fallback timezone, `Carbon::parse` would resolve it in app.timezone and
     * store a shifted wall clock, mirroring the read-path defect. An ISO string
     * carrying its own offset still wins, so the instant case is unchanged.
     */
    public function set(Model $model, string $key, mixed $value, array $attributes): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        return Carbon::parse($value, 'UTC')->utc()->format($model->getDateFormat());
    }
}
