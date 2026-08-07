<?php

namespace Zipavlin\Redline\Models\File;

use Illuminate\Contracts\Container\BindingResolutionException;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Schema\Blueprint;
use Orbit\Concerns\Orbital;
use Zipavlin\Redline\Models\Base\Report as BaseReport;
use Zipavlin\Redline\RedlineServiceProvider;

// Safe to load: skip class definition if orbit is not installed.
if (! RedlineServiceProvider::orbitConfigured()) {
    return;
}

class Report extends BaseReport
{
    use Orbital;

    protected static string $driver = 'json';

    /**
     * @throws BindingResolutionException
     */
    public static function getOrbitalPath()
    {
        return RedlineServiceProvider::makeStorageProvider()->databasePath().DIRECTORY_SEPARATOR.'reports';
    }

    public static function schema(Blueprint $table): void
    {
        $table->string('id');
        $table->string('status')->default('new');
        $table->string('author_id')->nullable();
        $table->string('url');
        $table->string('location');
        $table->text('body')->nullable();
        $table->json('pin')->nullable();
        $table->string('element')->nullable();
        $table->json('browser')->nullable();
        // created_at/updated_at are added by the Orbital trait (usesTimestamps),
        // defining them here too would create duplicate columns.
    }

    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'author_id');
    }

    public function screenshot(): HasOne
    {
        return $this->hasOne(Screenshot::class, 'report_id');
    }

    public function video(): HasOne
    {
        return $this->hasOne(Video::class, 'report_id');
    }

    public function comments(): HasMany
    {
        return $this->hasMany(Comment::class, 'report_id')->orderBy('created_at');
    }
}
