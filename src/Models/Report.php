<?php

namespace Zipavlin\Redline\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Zipavlin\Redline\Enums\Priority;
use Zipavlin\Redline\Enums\QueryOperator;
use Zipavlin\Redline\Enums\Status;
use Zipavlin\Redline\Http\Query\AggregateField;
use Zipavlin\Redline\Http\Query\FilterField;
use Zipavlin\Redline\Http\Query\IncludeField;
use Zipavlin\Redline\Http\Query\QuerySchema;
use Zipavlin\Redline\Http\Query\ResourceQuery;
use Zipavlin\Redline\Models\Casts\UtcDateTime;
use Zipavlin\Redline\Models\Concerns\HasQuerySchema;
use Zipavlin\Redline\Support\Members;

class Report extends Model
{
    use HasQuerySchema;
    use HasUuids;

    protected $connection = 'redline';

    protected $table = 'redline_reports';

    protected $fillable = [
        'id',
        'status',
        'priority',
        'due_at',
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
        // The column holds a naive UTC wall clock; see UtcDateTime. The built-in
        // 'datetime' cast reads it in the host's app.timezone and shifts the
        // serialized instant on any non-UTC host.
        'due_at' => UtcDateTime::class,
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
     * Serialize without `browser.user_agent`: the include-gate `browser` resolver
     * strips it on the grammar path, but a raw model serialization (the mutation
     * responses in ReportController) never goes through that resolver, so the
     * privacy rule is enforced at the serialization boundary as well.
     */
    public function toArray(): array
    {
        $array = parent::toArray();

        if (isset($array['browser']['user_agent'])) {
            unset($array['browser']['user_agent']);
        }

        return $array;
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

    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'author_id');
    }

    public function screenshot(): HasOne
    {
        return $this->hasOne(Screenshot::class, 'report_id');
    }

    public function video(): HasOne
    {
        return $this->hasOne(Video::class, 'report_id');
    }

    public function comments(): HasMany
    {
        return $this->hasMany(Comment::class, 'report_id')->orderBy('created_at');
    }

    public function members(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'redline_members', 'report_id', 'user_id');
    }

    /**
     * The single read-side exclusion point for the members projection is
     * Support\Members::forReport() - the author is never a member, and
     * `isAuthoredBy()` is the predicate both that helper and the write side
     * share.
     */
    public function isAuthoredBy(string $userId): bool
    {
        return $this->author_id !== null && $userId === $this->author_id;
    }

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
                // Closed-enum filter with concrete values only - unlike `status` there
                // is no group expansion, so each value is validated against Priority at
                // parse time and an unknown one lands in the collect-all-errors bag as
                // `filter.priority` (422). Column-based (rather than a whereIn resolver
                // like `status`) so `:not`/`:not_in` negate a single value/row as expected.
                'priority' => new FilterField(
                    operators: QueryOperator::string(),
                    column: 'priority',
                    normalize: function (mixed $value, string $operator, array &$errors): mixed {
                        if (in_array($operator, [QueryOperator::NULL->value, QueryOperator::NOT_NULL->value], true)) {
                            return $value;
                        }

                        $multiple = in_array($operator, [QueryOperator::IN->value, QueryOperator::NOT_IN->value], true);
                        $values = $multiple ? ResourceQuery::splitList($value) : [$value];

                        foreach ($values as $v) {
                            if (Priority::tryFrom((string) $v) === null) {
                                $errors['filter.priority'] = ["Unknown priority value [{$v}]."];

                                return null;
                            }
                        }

                        return $multiple ? array_values(array_unique($values)) : $value;
                    },
                ),
                // Closed-enum filter over the triage deadline state, not the raw
                // column: `due` is a *derived* state computed against now(), so it
                // resolves to a predicate rather than a column comparison. The urgency
                // states only match open statuses (a resolved report's stale deadline
                // is noise); `none` is the undated state regardless of status. Only a
                // concrete value is accepted - an unknown one feeds the
                // collect-all-errors bag as `filter.due` (422).
                'due' => new FilterField(
                    operators: [QueryOperator::EQ],
                    normalize: function (mixed $value, string $operator, array &$errors): mixed {
                        $normalized = match ((string) $value) {
                            'overdue', 'due_soon', 'none' => (string) $value,
                            default => null,
                        };

                        if ($normalized === null) {
                            $errors['filter.due'] = ["Unknown due value [{$value}]. Use [overdue], [due_soon] or [none]."];
                        }

                        return $normalized;
                    },
                    resolver: function (Builder $query, string $operator, mixed $value): void {
                        // `none` is deliberately NOT status-scoped: only the urgency
                        // states mean "unresolved and pressing", while a report with no
                        // deadline is simply undated whatever its status - a resolved
                        // report lacking due_at must still match. Return before the
                        // open-status scoping below so neither whereNotNull nor the
                        // status whereIn is applied.
                        if ($value === 'none') {
                            $query->whereNull('due_at');

                            return;
                        }

                        // Compare against UTC explicitly: due_at is always stored in UTC,
                        // so the wall clock now() would otherwise bind in the host's
                        // app timezone and shift the overdue/due-soon boundary.
                        $now = now()->utc();

                        $query->whereNotNull('due_at')->whereIn('status', Status::openValues());

                        if ($value === 'overdue') {
                            $query->where('due_at', '<', $now);

                            return;
                        }

                        $query->where('due_at', '>=', $now)->where('due_at', '<', $now->copy()->addDay());
                    },
                ),
                'body' => new FilterField(operators: QueryOperator::string(), column: 'body'),
                'url' => new FilterField(operators: QueryOperator::string(), column: 'url'),
                'created_at' => new FilterField(operators: QueryOperator::date(), column: 'created_at'),
                'due_at' => new FilterField(operators: QueryOperator::date(), column: 'due_at'),
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
                // Relation filter over the member pivot: `filter[member]=<uuid>`
                // -> whereHas on members.id. `:not`/`:not_in` negate the whole
                // membership predicate (see FilterField::apply()). No
                // relatedSchema/relatedFilters on purpose - the mechanism cannot reach
                // an attribute on a model reached THROUGH a pivot, so
                // `filter[member.name:like]` stays unsupported and 422s on the operator
                // allowlist instead of silently widening.
                'member' => new FilterField(
                    operators: QueryOperator::relation(),
                    relation: 'members',
                    relationColumn: 'id',
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
            sortable: ['created_at', 'status', 'location', 'priority', 'due_at'],
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
                'members' => new IncludeField(
                    relation: 'members',
                    operators: [QueryOperator::COUNT, QueryOperator::EXISTS],
                    eagerLoad: ['members:id,name'],
                    resolver: fn (Report $report): ?array => $report->relationLoaded('members')
                        ? Members::forReport($report)
                        : null,
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
                'priority' => new AggregateField(operators: [QueryOperator::COUNT], column: 'priority'),
                'location' => new AggregateField(
                    operators: [QueryOperator::COUNT, QueryOperator::UNIQUE],
                    column: 'location',
                    uniqueResolver: function (Builder $baseQuery): array {
                        return (clone $baseQuery)->whereNotNull('location')
                            ->distinct()->orderBy('location')->pluck('location')->all();
                    },
                ),
                // `author:count` (no value list) counts rows grouped by author_id -
                // { "<author-uuid>": count }.
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
            // Enum-ordered, not lexical: descending must put critical first. The
            // CASE fragment mirrors Priority's declaration order, with `normal` (1)
            // as the fallback for any unrecognised stored value.
            sortExpressions: [
                'priority' => "CASE priority WHEN 'low' THEN 0 WHEN 'normal' THEN 1 WHEN 'high' THEN 2 WHEN 'critical' THEN 3 ELSE 1 END",
            ],
            // `priority` and `due_at` are part of the report's base row, not include-gated.
            additionalBaseFields: ['priority', 'due_at'],
            // A missing deadline must never sort before a real one; see applySort().
            sortNullsLast: ['due_at'],
        );
    }
}
