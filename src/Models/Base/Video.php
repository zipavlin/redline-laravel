<?php

namespace Zipavlin\Redline\Models\Base;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Zipavlin\Redline\Providers\Storage\StorageProvider;

abstract class Video extends Model
{
    use HasUuids;

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
        $base = app(StorageProvider::class)->apiUrl()."/report/{$this->report_id}/video/{$this->id}/binary";

        return $token ? "{$base}?token={$token}" : $base;
    }

    abstract public function report(): BelongsTo;
}
