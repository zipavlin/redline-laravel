<?php

namespace Zipavlin\Redline\Models\Base;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Junction row linking one report to one assignee.
 *
 * Deliberately NOT a grammar resource of its own (no querySchema/HasQuerySchema):
 * its read surface is the `assignee` filter and the `assignees` include declared
 * on Base\Report. Exposing it directly would be a second, unpoliced path to the
 * same rows.
 */
abstract class Assignment extends Model
{
    use HasUuids;

    protected $fillable = [
        'id',
        'report_id',
        'user_id',
    ];

    abstract public function report(): BelongsTo;

    abstract public function user(): BelongsTo;
}
