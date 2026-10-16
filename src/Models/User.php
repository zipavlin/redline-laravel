<?php

namespace Zipavlin\Redline\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Zipavlin\Redline\Enums\Operation;
use Zipavlin\Redline\Enums\QueryOperator;
use Zipavlin\Redline\Enums\Role;
use Zipavlin\Redline\Enums\Status;
use Zipavlin\Redline\Http\Query\AggregateField;
use Zipavlin\Redline\Http\Query\FilterField;
use Zipavlin\Redline\Http\Query\IncludeField;
use Zipavlin\Redline\Http\Query\QuerySchema;
use Zipavlin\Redline\Http\Query\ResourceQuery;
use Zipavlin\Redline\Models\Concerns\HasQuerySchema;

class User extends Model
{
    use HasQuerySchema;
    use HasUuids;

    protected $connection = 'redline';

    protected $table = 'redline_users';

    protected $fillable = [
        'id',
        'name',
        'email',
        'role',
        'password',
        'status',
    ];

    protected $hidden = [
        'password',
        'token_version',
        'password_epoch',
    ];

    protected $casts = [
        'role' => Role::class,
    ];

    protected $appends = [
        'isApproved',
        'isPending',
        'isDeveloper',
        'isAdmin',
        'isGuest',
    ];

    public function getIsApprovedAttribute(): bool
    {
        return ($this->attributes['status'] ?? 'approved') === 'approved';
    }

    public function getIsPendingAttribute(): bool
    {
        return ($this->attributes['status'] ?? 'approved') === 'pending';
    }

    public function getIsDeveloperAttribute(): bool
    {
        return in_array($this->role, [Role::DEVELOPER, Role::ADMIN], true);
    }

    public function getIsAdminAttribute(): bool
    {
        return $this->role === Role::ADMIN;
    }

    public function getIsGuestAttribute(): bool
    {
        return $this->role === Role::GUEST;
    }

    public function getInitialsAttribute(): string
    {
        return str($this->name)
            ->explode(' ')
            ->take(2)
            ->map(fn ($word) => mb_strtoupper($word[0]))
            ->join('');
    }

    /**
     * Canonical storage and lookup form of an email address: trimmed and
     * lowercased. A non-string collapses to null rather than reaching a query.
     */
    public static function normalizeEmail(mixed $email): ?string
    {
        if (! is_string($email)) {
            return null;
        }

        return mb_strtolower(trim($email));
    }

    /**
     * Normalize on every write, so a case or whitespace variant can never squat
     * an existing address and lookups compare against a single stored form.
     */
    public function setEmailAttribute(mixed $value): void
    {
        $this->attributes['email'] = self::normalizeEmail($value);
    }

    /**
     * Look users up by email, comparing case-insensitively so a row written
     * before normalization still matches whoever owns it.
     */
    public function scopeWhereEmail(Builder $query, mixed $email): Builder
    {
        return $query->whereRaw('lower(email) = lower(?)', [self::normalizeEmail($email)]);
    }

    protected static function booted(): void
    {
        // The single choke point for password-epoch advancement: an UPDATE in
        // which the password column changed IS a password change, whichever
        // path wrote it (self/admin reset-password, redline:user --edit,
        // first registration update). API tokens snapshot this value at
        // creation, so the increment alone invalidates every older token.
        // Logout and bumpTokenVersion() save without touching the password and
        // must not - and do not - land here.
        static::updating(function (self $user): void {
            if ($user->isDirty('password')) {
                $user->password_epoch = (int) ($user->password_epoch ?? 0) + 1;
            }
        });
    }

    public function tokens(): HasMany
    {
        return $this->hasMany(Token::class);
    }

    /**
     * Invalidate every JWT issued for this user by advancing token_version.
     * Called on logout and password change. Set directly (not mass-assigned) so
     * the increment is not subject to the model's fillable whitelist.
     */
    public function bumpTokenVersion(): void
    {
        $this->token_version = (int) ($this->token_version ?? 0) + 1;
        $this->save();
    }

    public function can(Operation $operation, null|Report|Comment $model = null): bool
    {
        // allow guests to create reports
        if (config('redline.auth.guest', false) && $this->isGuest) {
            if ($operation === Operation::CREATE) {
                return true;
            }

            return false;
        }

        // disallow pending users
        if (! $this->isApproved) {
            return false;
        }

        return match ($operation) {
            // Body-content edits are author-only - developers/admins must NOT
            // rewrite someone else's report/comment body.
            Operation::EDIT => $model?->author_id === $this->id,
            // Deletion is owner OR admin - a plain developer may not delete
            // reports/comments they did not author.
            Operation::DELETE => $model !== null && ($this->isAdmin || $model->author_id === $this->id),
            // Status changes, pin moves and media attachments stay developer+ (or owner).
            Operation::UPDATE => $this->isDeveloper || $model?->author_id === $this->id,
            default => true,
        };
    }

    public function reports(): HasMany
    {
        return $this->hasMany(Report::class, 'author_id');
    }

    public function comments(): HasMany
    {
        return $this->hasMany(Comment::class, 'author_id');
    }

    /**
     * The query grammar whitelist for the /user collection endpoint, whose list
     * request is available to any authenticated user (writes and the other
     * endpoints stay admin-gated).
     *
     * SECURITY NOTE: this schema serves two contexts - its own endpoint (where
     * email/role/status filtering is fine, and PII stays behind explicit
     * include[]) AND relation-attribute delegation from sibling resources
     * (`filter[author.name:like]` on the all-roles /report endpoint). Those are
     * different trust levels. The delegating resource controls what it exposes
     * via FilterField::$relatedFilters (Report allows only `name`), so declaring
     * `email`/`role`/`status` here can NOT reopen the `filter[author.email:like]`
     * enumeration oracle on /report - a relation-attribute filter is a boolean
     * oracle read through meta.total, and the allowlist is what stops it. See
     * FilterField.
     */
    public static function querySchema(): QuerySchema
    {
        return new QuerySchema(
            filters: [
                // Virtual OR box, matching the pre-grammar UserController::index() `search`.
                // `email` is part of the OR box only for developers/admins - /user is
                // any-authenticated (the widget member picker) and an email LIKE match is
                // a prefix-walk oracle for a reporter, so non-developers search names only.
                'search' => new FilterField(
                    operators: [QueryOperator::LIKE],
                    resolver: function (Builder $query, string $operator, mixed $value, ResourceQuery $rq): void {
                        $needle = '%'.$value.'%';
                        $developer = $rq->user()?->isDeveloper === true;

                        $query->where(fn (Builder $inner) => $developer
                            ? $inner->where('name', 'like', $needle)->orWhere('email', 'like', $needle)
                            : $inner->where('name', 'like', $needle));
                    },
                ),
                'name' => new FilterField(operators: QueryOperator::string(), column: 'name'),
                // Declared (its own admin endpoint needs it, and Report's relatedFilters
                // allowlist is what keeps it unreachable through `filter[author.email]`),
                // but gated: a non-developer filter[] is a 422 at parse time, so the
                // boolean-oracle read through meta.total is closed, not merely unlisted.
                'email' => new FilterField(
                    operators: QueryOperator::string(),
                    column: 'email',
                    gate: fn (?User $user) => $user?->isDeveloper === true,
                ),
                'role' => new FilterField(operators: [QueryOperator::EQ, QueryOperator::IN], column: 'role'),
                'status' => new FilterField(operators: [QueryOperator::EQ, QueryOperator::IN], column: 'status'),
            ],
            sortable: ['name', 'created_at', 'reports_count', 'comments_count'],
            includable: [
                'name' => new IncludeField(column: 'name'),
                // Developer-only, and a denial is a 422 - unlike `browser`'s silent
                // omission, a reporter asking for `email` must be told, not handed a row
                // that quietly lacks the key.
                'email' => new IncludeField(
                    column: 'email',
                    gate: fn (?User $user) => $user?->isDeveloper === true,
                    gateErrors: true,
                ),
                'role' => new IncludeField(column: 'role'),
                'initials' => new IncludeField(column: 'initials'),
                'reports' => new IncludeField(relation: 'reports', operators: [QueryOperator::COUNT]),
                'comments' => new IncludeField(relation: 'comments', operators: [QueryOperator::COUNT]),
                'stats' => new IncludeField(
                    resolver: function (User $user): array {
                        $counts = app(Report::class)
                            ->where('author_id', $user->id)
                            ->selectRaw('status, count(*) as count')
                            ->groupBy('status')
                            ->pluck('count', 'status');

                        $sum = fn (array $values) => collect($values)->sum(fn ($v) => $counts[$v] ?? 0);

                        return [
                            'reports' => (int) $counts->sum(),
                            'open' => (int) $sum(Status::openValues()),
                            'resolved' => (int) $sum(Status::closedValues()),
                        ];
                    },
                ),
            ],
            aggregates: [
                'status' => new AggregateField(operators: [QueryOperator::COUNT], column: 'status'),
            ],
            // Users default to name-ascending (reports default created_at-descending); the
            // `id` tiebreaker makes it a total order for stable pagination.
            defaultSort: ['name' => 'asc', 'id' => 'asc'],
        );
    }
}
