<?php

namespace Zipavlin\Redline\Models\Database;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Zipavlin\Redline\Models\Base\Report as BaseReport;

class Report extends BaseReport
{
    protected $connection = 'redline';

    protected $table = 'redline_reports';

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
