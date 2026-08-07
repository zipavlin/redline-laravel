<?php

namespace Zipavlin\Redline\Models\Database;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Zipavlin\Redline\Models\Base\Screenshot as BaseScreenshot;

class Screenshot extends BaseScreenshot
{
    protected $connection = 'redline';

    protected $table = 'redline_screenshots';

    public function report(): BelongsTo
    {
        return $this->belongsTo(Report::class, 'report_id');
    }
}
