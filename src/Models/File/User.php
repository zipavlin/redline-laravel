<?php

namespace Zipavlin\Redline\Models\File;

use Illuminate\Contracts\Container\BindingResolutionException;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Schema\Blueprint;
use Orbit\Concerns\Orbital;
use Zipavlin\Redline\Models\Base\User as BaseUser;
use Zipavlin\Redline\RedlineServiceProvider;

// Safe to load: skip class definition if orbit is not installed.
if (! RedlineServiceProvider::orbitConfigured()) {
    return;
}

class User extends BaseUser
{
    use Orbital;

    protected static string $driver = 'json';

    /**
     * @throws BindingResolutionException
     */
    public static function getOrbitalPath()
    {
        return RedlineServiceProvider::makeStorageProvider()->databasePath().DIRECTORY_SEPARATOR.'users';
    }

    public static function schema(Blueprint $table): void
    {
        $table->string('id');
        $table->string('name');
        $table->string('email');
        $table->string('role');
        $table->string('password')->nullable();
        $table->string('status')->default('approved');
        $table->integer('token_version')->nullable();
    }

    public function reports(): HasMany
    {
        return $this->hasMany(Report::class, 'author_id');
    }

    public function comments(): HasMany
    {
        return $this->hasMany(Comment::class, 'author_id');
    }

    public function assignments(): HasMany
    {
        return $this->hasMany(Assignment::class, 'user_id');
    }
}
