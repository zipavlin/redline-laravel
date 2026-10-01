<?php

namespace Zipavlin\Redline\Models\Database;

use Illuminate\Database\Eloquent\Relations\HasMany;
use Zipavlin\Redline\Models\Base\User as BaseUser;

class User extends BaseUser
{
    protected $connection = 'redline';

    protected $table = 'redline_users';

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
