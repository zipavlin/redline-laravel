<?php

namespace Zipavlin\Redline\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Zipavlin\Redline\Providers\Storage\StorageProvider;

class Screenshot extends Model
{
    use HasUuids;

    protected $connection = 'redline';

    protected $table = 'redline_screenshots';

    public $timestamps = false;

    protected $fillable = [
        'id',
        'report_id',
        'path',
    ];

    protected $appends = ['url'];

    /**
     * The binary endpoint's URL - authenticated by the caller's own Bearer token
     * or HttpOnly cookie. No token is appended: RedlineApiAuth refuses tokens in
     * the query string, so such a suffix only leaked the JWT into logs/Referer.
     */
    public function getUrlAttribute(): string
    {
        return app(StorageProvider::class)->apiUrl()."/report/{$this->report_id}/screenshot/{$this->id}/binary";
    }

    public function report(): BelongsTo
    {
        return $this->belongsTo(Report::class, 'report_id');
    }
}
