<?php

namespace Zipavlin\Redline\Models\File;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Schema\Blueprint;
use RyanChandler\Orbit\Concerns\Orbital;
use Zipavlin\Redline\Models\Base\Comment as BaseComment;

// Safe to load: skip class definition if orbit is not installed.
if (! trait_exists('RyanChandler\Orbit\Concerns\Orbital', false)) {
    return;
}

class Comment extends BaseComment
{
    use Orbital;

    protected static string $driver = 'json';

    public static function schema(Blueprint $table): void
    {
        $table->string('id');
        $table->string('report_id');
        $table->string('author_id')->nullable();
        $table->text('body');
        $table->timestamp('created_at')->nullable();
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
