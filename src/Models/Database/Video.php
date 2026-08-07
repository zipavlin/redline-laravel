<?php

namespace Zipavlin\Redline\Models\Database;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Zipavlin\Redline\Models\Base\Video as BaseVideo;

class Video extends BaseVideo
{
    protected $connection = 'redline';

    protected $table = 'redline_videos';

    public function report(): BelongsTo
    {
        return $this->belongsTo(Report::class, 'report_id');
    }
}
