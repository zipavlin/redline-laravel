<?php

namespace Zipavlin\Redline\Models\Database;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Zipavlin\Redline\Models\Base\Comment as BaseComment;

class Comment extends BaseComment
{
    protected $connection = 'redline';

    protected $table = 'redline_comments';

    public function report(): BelongsTo
    {
        return $this->belongsTo(Report::class, 'report_id');
    }

    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'author_id');
    }
}
