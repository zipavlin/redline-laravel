<?php

namespace Zipavlin\Redline\Models\File;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Schema\Blueprint;
use RyanChandler\Orbit\Concerns\Orbital;
use Zipavlin\Redline\Models\Base\Screenshot as BaseScreenshot;

// Safe to load: skip class definition if orbit is not installed.
if (! trait_exists('RyanChandler\Orbit\Concerns\Orbital', false)) {
    return;
}

class Screenshot extends BaseScreenshot
{
    use Orbital;

    protected static string $driver = 'json';

    public static function schema(Blueprint $table): void
    {
        $table->string('id');
        $table->string('report_id');
        $table->string('path');
    }

    public function report(): BelongsTo
    {
        return $this->belongsTo(Report::class, 'report_id');
    }
}
