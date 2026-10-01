<?php

namespace Zipavlin\Redline\Models\Database;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Zipavlin\Redline\Models\Base\Assignment as BaseAssignment;

class Assignment extends BaseAssignment
{
    protected $connection = 'redline';

    protected $table = 'redline_assignments';

    public function report(): BelongsTo
    {
        return $this->belongsTo(Report::class, 'report_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}
