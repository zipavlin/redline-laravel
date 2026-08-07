<?php

namespace Zipavlin\Redline\Models\File;

use Illuminate\Contracts\Container\BindingResolutionException;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Schema\Blueprint;
use Orbit\Concerns\Orbital;
use Zipavlin\Redline\Models\Base\Screenshot as BaseScreenshot;
use Zipavlin\Redline\RedlineServiceProvider;

// Safe to load: skip class definition if orbit is not installed.
if (! RedlineServiceProvider::orbitConfigured()) {
    return;
}

class Screenshot extends BaseScreenshot
{
    use Orbital;

    protected static string $driver = 'json';

    /**
     * @throws BindingResolutionException
     */
    public static function getOrbitalPath()
    {
        return RedlineServiceProvider::makeStorageProvider()->databasePath().DIRECTORY_SEPARATOR.'screenshots';
    }

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
