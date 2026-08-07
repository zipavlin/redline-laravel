<?php

namespace Zipavlin\Redline\Models\File;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Schema\Blueprint;
use RyanChandler\Orbit\Concerns\Orbital;
use Zipavlin\Redline\Models\Base\Report as BaseReport;

// Safe to load: skip class definition if orbit is not installed.
if (! trait_exists('RyanChandler\Orbit\Concerns\Orbital', false)) {
    return;
}

class Report extends BaseReport
{
    use Orbital;

    protected static string $driver = 'json';

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
        $table->timestamps();
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
