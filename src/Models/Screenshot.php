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

    public function getUrlAttribute(): string
    {
        $token = request()->bearerToken();
        $base = app(StorageProvider::class)->apiUrl()."/report/{$this->report_id}/screenshot/{$this->id}/binary";

        return $token ? "{$base}?token={$token}" : $base;
    }

    public function report(): BelongsTo
    {
        return $this->belongsTo(Report::class, 'report_id');
    }
}
