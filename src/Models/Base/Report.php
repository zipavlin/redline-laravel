<?php

namespace Zipavlin\Redline\Models\Base;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Zipavlin\Redline\Enums\QueryOperator;
use Zipavlin\Redline\Enums\Status;
use Zipavlin\Redline\Http\Query\AggregateField;
use Zipavlin\Redline\Http\Query\FilterField;
use Zipavlin\Redline\Http\Query\IncludeField;
use Zipavlin\Redline\Http\Query\QuerySchema;
use Zipavlin\Redline\Http\Query\ResourceQuery;
use Zipavlin\Redline\Models\Concerns\HasQuerySchema;
use Zipavlin\Redline\Support\AssigneeRow;

abstract class Report extends Model
{
    use HasQuerySchema;
    use HasUuids;

    protected $fillable = [
        'id',
        'status',
        'author_id',
        'url',
        'location',
        'body',
        'pin',
        'element',
        'browser',
    ];

    protected $casts = [
        'pin' => 'array',
        'browser' => 'array',
    ];

    /**
     * A short title derived from the body: first non-empty line, tags
     * stripped, trimmed, truncated to ~60 chars with an ellipsis when cut.
     * Deliberately NOT in $appends - like every other derived field, it's
     * include-gated (`include[title]`), not auto-serialized everywhere.
     */
    public function getTitleAttribute(): string
    {
        $first = strtok((string) strip_tags((string) $this->body), "\n") ?: '';
        $first = trim($first);

        return str($first)->limit(60)->toString();
    }

    /**
     * Statuses this user may move this report to right now, given the report's
     * current status. A transition must satisfy both the role's allowed target
     * set and the lifecycle chain.
     *
     * @return Status[]
     */
    public function allowedStatusTargets(User $user): array
    {
        $targets = $user->isDeveloper
            ? [Status::NOTICED, Status::PROGRESS, Status::RESOLVED]
            : [Status::PROGRESS, Status::DONE]; // reporter / owner

        $from = Status::from($this->status);

        return array_values(array_filter(
            $targets,
            fn (Status $to) => $from->canTransitionTo($to),
        ));
    }

    public function canUserSetStatus(User $user, Status $to): bool
    {
        return in_array($to, $this->allowedStatusTargets($user), true);
    }

    abstract public function author(): BelongsTo;

    abstract public function screenshot(): HasOne;

    abstract public function video(): HasOne;

    abstract public function comments(): HasMany;

    abstract public function assignments(): HasMany;

    /**
     * The query grammar whitelist for this resource - see the grammar docblock
     * on QuerySchema for the wire format each entry below enables.
     */
    public static function querySchema(): QuerySchema
    {
        return new QuerySchema(
            filters: [
                // `status` accepts either a group (unresolved/resolved) or a concrete
                // status value; see the STATUS GROUPS vs CONCRETE STATUSES note on
                // ReportController. Reused verbatim by aggregate()'s bucketed counts.
                'status' => new FilterField(
                    operators: QueryOperator::string(),
                    // Group-vs-concrete-value expansion runs at parse time so an unknown
                    // status feeds the collect-all-errors bag instead of throwing mid-apply.
                    resolver: function (Builder $query, string $operator, mixed $value): void {
                        $query->whereIn('status', $value);
                    },
                    normalize: function (mixed $value, string $operator, array &$errors): mixed {
                        $values = $operator === 'in' ? ResourceQuery::splitList($value) : [$value];
                        $resolved = [];

                        foreach ($values as $v) {
                            $expanded = match ($v) {
                                'unresolved' => Status::openValues(),
                                'resolved' => Status::closedValues(),
                                default => Status::tryFrom($v) ? [$v] : null,
                            };

                            if ($expanded === null) {
                                $errors['filter.status'] = ["Unknown status or status group [{$v}]."];

                                return null;
                            }

                            $resolved = [...$resolved, ...$expanded];
                        }

                        return array_values(array_unique($resolved));
                    },
                ),
                'body' => new FilterField(operators: QueryOperator::string(), column: 'body'),
                'created_at' => new FilterField(operators: QueryOperator::date(), column: 'created_at'),
                'location' => new FilterField(operators: QueryOperator::string(), column: 'location'),
                // Relation filter: `filter[author]=<uuid>` / `filter[author.id:in]=a,b`
                // (eq/in on the FK), `filter[author:exists]`, and `filter[author.name:like]`
                // delegated to User's own schema. Only `name` is reachable here even once
                // User exposes email/role/status for its admin-only endpoint - see
                // FilterField::$relatedFilters.
                'author' => new FilterField(
                    operators: QueryOperator::relation(),
                    relation: 'author',
                    relationColumn: 'id',
                    relatedSchema: fn () => User::querySchema(),
                    relatedFilters: ['name'],
                ),
                // Relation filter over the assignment junction: `filter[assignee]=<uuid>`
                // -> whereHas on assignments.user_id. No relatedSchema/relatedFilters on
                // purpose - the mechanism cannot reach an attribute on a model reached
                // THROUGH a junction, so `filter[assignee.name:like]` stays unsupported
                // and 422s on the operator allowlist instead of silently widening.
                'assignee' => new FilterField(
                    operators: QueryOperator::relation(),
                    relation: 'assignments',
                    relationColumn: 'user_id',
                ),
                // Virtual OR-field: matches the pre-grammar `search` box (body OR location).
                'search' => new FilterField(
                    operators: [QueryOperator::LIKE],
                    resolver: function (Builder $query, string $operator, mixed $value): void {
                        $needle = '%'.$value.'%';
                        $query->where(fn (Builder $inner) => $inner
                            ->where('body', 'like', $needle)
                            ->orWhere('location', 'like', $needle));
                    },
                ),
                'mine' => new FilterField(
                    operators: [QueryOperator::EQ],
                    resolver: function (Builder $query, string $operator, mixed $value, ResourceQuery $rq): void {
                        if (FilterField::truthy($value)) {
                            $query->where('author_id', $rq->user()?->id);
                        }
                    },
                ),
                // `filter[assigned]=1` -> "assigned to me"; `=0` -> "not assigned to me"
                // (never a silent no-op, so a typo cannot widen the result set).
                // Complements `filter[mine]=1` (authored by me).
                'assigned' => new FilterField(
                    operators: [QueryOperator::EQ],
                    resolver: function (Builder $query, string $operator, mixed $value, ResourceQuery $rq): void {
                        $userId = $rq->user()?->id;

                        $value
                            ? $query->whereHas('assignments', fn (Builder $inner) => $inner->where('user_id', $userId))
                            : $query->doesntHave('assignments', 'and', fn (Builder $inner) => $inner->where('user_id', $userId));
                    },
                    // Strict parse-time truthy/falsy so a typo'd value 422s instead of
                    // falling through the resolver's falsy branch as "not assigned".
                    normalize: function (mixed $value, string $operator, array &$errors): ?bool {
                        if (FilterField::truthy($value)) {
                            return true;
                        }

                        if (FilterField::falsy($value)) {
                            return false;
                        }

                        $errors['filter.assigned'] = ["Invalid value [{$value}] for filter[assigned] - use 1/true or 0/false."];

                        return null;
                    },
                ),
                // Relationship existence. `=1`/`true` -> has(), `=0`/`false` -> doesntHave();
                // anything else 422s rather than silently matching neither (the old
                // truthy()-only check on has_media/has_comments let a typo'd value fall
                // through as "don't filter at all", which is what this replaces).
                // NOTE: this replaces `has_media`, which was an OR across screenshot/video
                // (`?has_media=1` meant "has a screenshot OR a video"). Two separate
                // filter[]s are AND-combined (see the grammar's KNOWN BOUND), so
                // `filter[screenshot:exists]=1&filter[video:exists]=1` means "has both",
                // not "has either" - "has any media" is no longer expressible in one call.
                // Existence-only relation filters: `filter[screenshot:exists]=1|0` etc.
                // (a report's media/comments aren't filtered BY an attribute - path is an
                // internal storage detail, and filtering reports by comment content is
                // double-nested delegation the mechanism doesn't do yet). Comment has its
                // own richer querySchema() for its own endpoint; it just isn't reached from
                // here. Existence needs no relatedSchema - FilterField::apply() maps EXISTS
                // to has()/doesntHave() generically.
                'screenshot' => new FilterField(operators: [QueryOperator::EXISTS], relation: 'screenshot'),
                'video' => new FilterField(operators: [QueryOperator::EXISTS], relation: 'video'),
                'comments' => new FilterField(operators: [QueryOperator::EXISTS], relation: 'comments'),
            ],
            sortable: ['created_at', 'status', 'location'],
            includable: [
                'title' => new IncludeField(column: 'title'),
                'body' => new IncludeField(column: 'body'),
                'pin' => new IncludeField(column: 'pin'),
                'element' => new IncludeField(column: 'element'),
                'url' => new IncludeField(column: 'url'),
                'location' => new IncludeField(column: 'location'),
                // Developer-only, and user_agent is always stripped regardless of
                // what was asked for - a privacy rule independent of include[].
                'browser' => new IncludeField(
                    gate: fn (?User $user) => $user?->isDeveloper === true,
                    resolver: function (Report $report): ?array {
                        if (! $report->browser) {
                            return null;
                        }

                        $browser = $report->browser;
                        unset($browser['user_agent']);

                        return $browser;
                    },
                ),
                'author' => new IncludeField(
                    relation: 'author',
                    attributes: ['id', 'name', 'email'],
                    default: ['id', 'name'],
                ),
                // Target name is `assignees`; the Eloquent relation is `assignments`.
                // withCount()/withExists() attach relation-named `assignments_count`/
                // `assignments_exists`, while serialize() emits target-named
                // `assignees_count`/`assignees_exists` - see ResourceQuery::serialize().
                // Do NOT "fix" IncludeField::value() to read the target name: the
                // attribute attached to the model really is relation-named.
                'assignees' => new IncludeField(
                    relation: 'assignments',
                    operators: [QueryOperator::COUNT, QueryOperator::EXISTS],
                    eagerLoad: ['assignments.user:id,name'],
                    resolver: function (Report $report): ?array {
                        if (! $report->relationLoaded('assignments')) {
                            return null;
                        }

                        return AssigneeRow::fromAssignments($report->assignments);
                    },
                ),
                // `url` reads Screenshot::$report_id and $id - both are always
                // selected regardless of the requested attribute list.
                'screenshot' => new IncludeField(
                    relation: 'screenshot',
                    operators: [QueryOperator::EXISTS],
                    attributes: ['id', 'url'],
                    needs: ['url' => ['report_id', 'id']],
                ),
                'video' => new IncludeField(
                    relation: 'video',
                    operators: [QueryOperator::EXISTS],
                    attributes: ['id', 'url'],
                    needs: ['url' => ['report_id', 'id']],
                ),
                // No per-attribute selection - each comment's shape (including
                // its nested author snippet) is fixed, so this needs its own
                // eager-load spec (the generic attrs-derived one can't express
                // a nested `comments.author` load) and its own resolver.
                'comments' => new IncludeField(
                    relation: 'comments',
                    operators: [QueryOperator::COUNT, QueryOperator::EXISTS],
                    eagerLoad: ['comments.author:id,name'],
                    resolver: function (Report $report): ?array {
                        if (! $report->relationLoaded('comments')) {
                            return null;
                        }

                        return $report->comments->map(fn (Comment $comment) => [
                            'id' => $comment->id,
                            'body' => $comment->body,
                            'created_at' => $comment->created_at,
                            'author' => $comment->author ? ['id' => $comment->author->id, 'name' => $comment->author->name] : null,
                        ])->all();
                    },
                ),
            ],
            // The `id` tiebreaker is load-bearing, not decorative: `created_at`
            // alone isn't a total order (rows created in a tight loop can share
            // a timestamp), so pagination pages can otherwise legitimately
            // overlap or skip rows between requests.
            aggregates: [
                'status' => new AggregateField(operators: [QueryOperator::COUNT], column: 'status'),
                'location' => new AggregateField(
                    operators: [QueryOperator::COUNT, QueryOperator::UNIQUE],
                    column: 'location',
                    uniqueResolver: function (Builder $baseQuery): array {
                        return (clone $baseQuery)->whereNotNull('location')
                            ->distinct()->orderBy('location')->pluck('location')->all();
                    },
                ),
                // `author:count` (no value list) counts rows grouped by author_id -
                // { "<author-uuid>": count }. Works for both Database and File
                // drivers: author_id exists in both schemas.
                'author' => new AggregateField(
                    operators: [QueryOperator::COUNT, QueryOperator::UNIQUE],
                    column: 'author_id',
                    uniqueResolver: function (Builder $baseQuery): array {
                        $ids = (clone $baseQuery)->whereNotNull('author_id')->distinct()->pluck('author_id');

                        return app(User::class)->whereIn('id', $ids)->orderBy('name')->get(['id', 'name'])
                            ->map(fn (User $user) => ['id' => $user->id, 'name' => $user->name])
                            ->all();
                    },
                ),
            ],
            defaultSort: ['created_at' => 'desc', 'id' => 'desc'],
        );
    }
}
