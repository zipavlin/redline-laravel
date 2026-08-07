<?php

namespace Zipavlin\Redline\Models\Base;

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
use Zipavlin\Redline\Models\Concerns\HasQuerySchema;

abstract class User extends Model
{
    use HasQuerySchema;
    use HasUuids;

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
     * Invalidate every JWT issued for this user by advancing token_version.
     * Called on logout and password change. Set directly (not mass-assigned) so
     * it works across Eloquent (Database) and Orbit (File) implementations.
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

    abstract public function reports(): HasMany;

    abstract public function comments(): HasMany;

    /**
     * The query grammar whitelist for the admin-only /user collection endpoint.
     *
     * SECURITY NOTE: this schema serves two contexts - its own admin-gated
     * endpoint (where email/role/status filtering is fine) AND relation-attribute
     * delegation from sibling resources (`filter[author.name:like]` on the
     * all-roles /report endpoint). Those are different trust levels. The
     * delegating resource controls what it exposes via FilterField::$relatedFilters
     * (Report allows only `name`), so declaring `email`/`role`/`status` here can
     * NOT reopen the `filter[author.email:like]` enumeration oracle on /report -
     * a relation-attribute filter is a boolean oracle read through meta.total,
     * and the allowlist is what stops it. See FilterField.
     */
    public static function querySchema(): QuerySchema
    {
        return new QuerySchema(
            filters: [
                // Virtual OR box, matching the pre-grammar UserController::index() `search`.
                'search' => new FilterField(
                    operators: [QueryOperator::LIKE],
                    resolver: function (Builder $query, string $operator, mixed $value): void {
                        $needle = '%'.$value.'%';
                        $query->where(fn (Builder $inner) => $inner
                            ->where('name', 'like', $needle)
                            ->orWhere('email', 'like', $needle));
                    },
                ),
                'name' => new FilterField(operators: QueryOperator::string(), column: 'name'),
                'email' => new FilterField(operators: QueryOperator::string(), column: 'email'),
                'role' => new FilterField(operators: [QueryOperator::EQ, QueryOperator::IN], column: 'role'),
                'status' => new FilterField(operators: [QueryOperator::EQ, QueryOperator::IN], column: 'status'),
            ],
            sortable: ['name', 'created_at', 'reports_count', 'comments_count'],
            includable: [
                'name' => new IncludeField(column: 'name'),
                'email' => new IncludeField(column: 'email'),
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
