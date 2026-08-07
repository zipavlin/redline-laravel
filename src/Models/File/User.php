<?php

namespace Zipavlin\Redline\Models\File;

use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Schema\Blueprint;
use RyanChandler\Orbit\Concerns\Orbital;
use Zipavlin\Redline\Models\Base\User as BaseUser;

// Safe to load: skip class definition if orbit is not installed.
if (! trait_exists('RyanChandler\Orbit\Concerns\Orbital', false)) {
    return;
}

class User extends BaseUser
{
    use Orbital;

    protected static string $driver = 'json';

    public static function schema(Blueprint $table): void
    {
        $table->string('id');
        $table->string('name');
        $table->string('email');
        $table->string('role');
        $table->string('password')->nullable();
        $table->string('status')->default('approved');
        $table->integer('token_version')->nullable();
        $table->timestamp('created_at')->nullable();
    }

    public function reports(): HasMany
    {
        return $this->hasMany(Report::class, 'author_id');
    }

    public function comments(): HasMany
    {
        return $this->hasMany(Comment::class, 'author_id');
    }
}
