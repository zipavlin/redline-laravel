<?php

namespace Zipavlin\Redline\Models\File;

use Illuminate\Contracts\Container\BindingResolutionException;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Schema\Blueprint;
use Orbit\Concerns\Orbital;
use Zipavlin\Redline\Models\Base\Comment as BaseComment;
use Zipavlin\Redline\RedlineServiceProvider;

// Safe to load: skip class definition if orbit is not installed.
if (! RedlineServiceProvider::orbitConfigured()) {
    return;
}

class Comment extends BaseComment
{
    use Orbital;

    protected static string $driver = 'json';

    /**
     * @throws BindingResolutionException
     */
    public static function getOrbitalPath()
    {
        return RedlineServiceProvider::makeStorageProvider()->databasePath().DIRECTORY_SEPARATOR.'comments';
    }

    public static function schema(Blueprint $table): void
    {
        $table->string('id');
        $table->string('report_id');
        $table->string('author_id')->nullable();
        $table->text('body');
        // created_at/updated_at are added by the Orbital trait (usesTimestamps),
        // defining them here too would create duplicate columns.
    }

    public function report(): BelongsTo
    {
        return $this->belongsTo(Report::class, 'report_id');
    }

    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'author_id');
    }
}
