<?php

namespace Zipavlin\Redline\Http\Query;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Zipavlin\Redline\Enums\QueryCapability;
use Zipavlin\Redline\Enums\QueryOperator;
use Zipavlin\Redline\Models\User;

/**
 * A parsed-and-ready query against one resource's QuerySchema. Entry point is
 * <Model>::resourceQuery($request) - see HasQuerySchema. Typical use:
 *
 *   Report::resourceQuery($request)->use(QueryCapability::FILTER, QueryCapability::INCLUDE)->parse();
 *
 * ->use() narrows which parameter groups this request accepts. Sending a
 * group that isn't in that list (e.g. filter[] on a show() request) is a
 * 422, never a silent drop - that's what makes the grammar safe to generate
 * clients from. Omitting ->use() entirely accepts every group.
 *
 * See the grammar docblock on ReportController for the wire format each
 * group/operator enables.
 */
final class ResourceQuery
{
    private const int|null DEFAULT_PER_PAGE = null;

    private const int MAX_PER_PAGE = 100;

    private ?ParsedQuery $parsed = null;

    /** @var array<int, QueryCapability>|null null = every capability accepted */
    private ?array $capabilities = null;

    public function __construct(
        private readonly QuerySchema $schema,
        private readonly Request $request,
        private readonly string $modelClass,
    ) {}

    public function schema(): QuerySchema
    {
        return $this->schema;
    }

    public function request(): Request
    {
        return $this->request;
    }

    /** The authenticated user - for resolvers that need it (e.g. `mine`). */
    public function user(): ?User
    {
        $user = $this->request->user();

        return $user instanceof User ? $user : null;
    }

    /** Split a comma-separated value (or pass an already-array value through). */
    public static function splitList(mixed $value): array
    {
        return is_array($value) ? $value : array_map('trim', explode(',', (string) $value));
    }

    /**
     * Restrict which parameter groups this request accepts. Call before
     * parse(). A group not listed here 422s if the caller sent it anyway,
     * rather than silently ignoring it.
     */
    public function use(QueryCapability ...$capabilities): static
    {
        $this->capabilities = $capabilities;

        return $this;
    }

    private function accepts(QueryCapability $capability): bool
    {
        return $this->capabilities === null || in_array($capability, $this->capabilities, true);
    }

    /**
     * Parse every accepted parameter group, collecting every violation into
     * one 422 (never failing on the first) - params are never silently
     * dropped, including ones excluded via ->use().
     */
    public function parse(): static
    {
        if ($this->parsed !== null) {
            return $this;
        }

        $errors = [];

        $filters = [];
        if ($this->accepts(QueryCapability::FILTER)) {
            $filters = $this->parseFilters($errors);
        } else {
            $this->rejectIfPresent('filter', $errors);
        }

        $include = [];
        if ($this->accepts(QueryCapability::INCLUDE)) {
            $include = $this->parseInclude($errors);
        } else {
            $this->rejectIfPresent('include', $errors);
        }

        $sort = [];
        if ($this->accepts(QueryCapability::SORT)) {
            $sort = $this->parseSort($errors);
        } else {
            $this->rejectIfPresent('sort', $errors);
        }

        $perPage = self::DEFAULT_PER_PAGE;
        $page = 1;
        if ($this->accepts(QueryCapability::PAGINATE)) {
            [$perPage, $page] = $this->parsePaginate($errors);
        } else {
            $this->rejectIfPresent('paginate', $errors);
        }

        $aggregate = [];
        if ($this->accepts(QueryCapability::AGGREGATE)) {
            $aggregate = $this->parseAggregate($errors);
        } else {
            $this->rejectIfPresent('aggregate', $errors);
        }

        $groupBy = null;
        if ($this->accepts(QueryCapability::GROUP)) {
            $groupBy = $this->parseGroupBy($errors);
        } else {
            $this->rejectIfPresent('group_by', $errors);
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        $this->parsed = new ParsedQuery(
            filters: $filters,
            sort: $sort,
            include: $include,
            perPage: $perPage,
            page: $page,
            aggregate: $aggregate,
            groupBy: $groupBy,
        );

        return $this;
    }

    public function parsedQuery(): ParsedQuery
    {
        $this->assertParsed();

        return $this->parsed;
    }

    /** Fresh, container-resolved builder with every parsed filter applied. */
    public function builder(): Builder
    {
        $this->assertParsed();

        $builder = app($this->modelClass)->newQuery();

        foreach ($this->parsed->filters as $filter) {
            if (isset($filter['delegate'])) {
                $delegate = $filter['delegate'];

                $builder->whereHas(
                    $filter['field']->relation,
                    fn (Builder $inner) => $delegate['field']->apply($inner, $delegate, $this),
                );

                continue;
            }

            $filter['field']->apply($builder, $filter, $this);
        }

        return $builder;
    }

    /**
     * @param  array<int, array{target: string, dir: string}>  $sort
     * @param  array<string, string>  $sortExpressions  target => raw SQL fragment, for enum-ordered sorts
     */
    public static function applySort(Builder $builder, array $sort, array $sortExpressions = []): Builder
    {
        foreach ($sort as $s) {
            $expression = $sortExpressions[$s['target']] ?? null;

            if ($expression === null) {
                $builder->orderBy($s['target'], $s['dir']);

                continue;
            }

            // The direction is already whitelisted to asc/desc in parseSort(), and
            // the expression is a developer-authored schema fragment (never user
            // input), so both are safe to interpolate here.
            $builder->orderByRaw('('.$expression.') '.$s['dir']);
        }

        return $builder;
    }

    /**
     * GET /{resource}/aggregate terminal. `group_by` only affects the
     * grouped-count form (a `count` with no explicit value list) - see
     * AggregateField::compute().
     */
    public function aggregate(): array
    {
        $this->assertParsed();

        $base = $this->builder();
        $result = [];

        foreach ($this->parsed->aggregate as $request) {
            $field = $request['field'];

            $result[$request['target']][$request['operator']] = $request['value'] !== null && $request['operator'] === 'count'
                ? $this->countBuckets($base, $request['target'], self::splitList($request['value']))
                : $field->compute($base, $request['operator'], $this->parsed->groupBy);
        }

        return $result;
    }

    /**
     * Bucketed counts (`aggregate[status:count]=unresolved,resolved`) reuse
     * the sibling FilterField's *full* parse+apply pipeline (not apply()
     * alone) - e.g. so `status`'s group-vs-concrete-value expansion, which
     * runs in normalize() at parse time, also applies to bucket keys. That
     * cross-field reuse is why this lives here rather than on AggregateField.
     *
     * @return array<string, int> bucket value => count
     */
    private function countBuckets(Builder $baseQuery, string $target, array $values): array
    {
        $filterField = $this->schema->filterFor($target, null);
        $counts = [];

        foreach ($values as $value) {
            $q = clone $baseQuery;

            if ($filterField) {
                $errors = [];
                $parsedFilter = $filterField->parse("{$target}:eq", $target, null, 'eq', $value, $errors);

                if ($errors !== []) {
                    throw ValidationException::withMessages($errors);
                }

                $filterField->apply($q, $parsedFilter, $this);
            } else {
                $q->where($target, $value);
            }

            $counts[$value] = $q->count();
        }

        return $counts;
    }

    /**
     * GET /{resource} terminal - {data, meta} envelope, rows shaped by the
     * parsed include[]. Base row is always `id`, `status`, `created_at` plus
     * any schema-declared additional base fields; everything else (including
     * `body` and `author`) is include-gated.
     */
    public function list(): array
    {
        $this->assertParsed();

        $query = $this->builder();
        $this->applyEagerLoads($query);

        if ($this->parsed->sort === []) {
            foreach ($this->schema->defaultSort as $column => $dir) {
                $query->orderBy($column, $dir);
            }
        } else {
            self::applySort($query, $this->parsed->sort, $this->schema->sortExpressions);
        }

        if ($this->parsed->perPage === null) {
            return $this->unpaginatedList($query);
        }

        $paginator = $query->paginate(perPage: $this->parsed->perPage, page: $this->parsed->page);

        return [
            'data' => collect($paginator->items())->map(fn (Model $model) => $this->row($model))->all(),
            'meta' => [
                'total' => $paginator->total(),
                'per_page' => $paginator->perPage(),
                'current_page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
            ],
        ];
    }

    /**
     * `paginate[per_page]` omitted entirely - no MAX_PER_PAGE ceiling applies
     * (that only bounds an explicit per_page), so this returns every matching
     * row unless a host opts into config('redline.api.max_rows') (off/null
     * by default). `meta` is just `{total}` - no per_page/current_page/last_page,
     * since there's no pagination to describe.
     */
    private function unpaginatedList(Builder $query): array
    {
        $maxRows = config('redline.api.max_rows');

        if ($maxRows === null) {
            $rows = $query->get();

            return [
                'data' => $rows->map(fn (Model $model) => $this->row($model))->all(),
                'meta' => ['total' => $rows->count()],
            ];
        }

        // A fresh filters-only builder for the true total - the eager loads/sort
        // already applied to $query don't affect a plain count, but building a
        // separate one avoids relying on that being true across storage drivers.
        $total = $this->builder()->count();
        $rows = $query->limit((int) $maxRows)->get();

        return [
            'data' => $rows->map(fn (Model $model) => $this->row($model))->all(),
            'meta' => ['total' => $total],
        ];
    }

    /**
     * GET /{resource}/{id} terminal - one resource, shaped by the parsed
     * include[]. Callers needing role-gated fields beyond what a `gate:`
     * IncludeField expresses (e.g. `show()`'s auto-transition side effect)
     * still read $model directly; this only covers the include-driven shape.
     */
    public function hydrate(Model $model): array
    {
        $this->assertParsed();
        $this->applyEagerLoads($model);

        return $this->row($model);
    }

    /**
     * @param  Builder|Model  $target  Builder before the query runs (list()), or
     *                                 a single hydrated Model (hydrate()) - both
     *                                 expose with()/withCount()/withExists() (Builder)
     *                                 or load()/loadCount()/loadExists() (Model).
     */
    private function applyEagerLoads(Builder|Model $target): void
    {
        $plan = $this->eagerLoadPlan();
        $isBuilder = $target instanceof Builder;

        if ($plan['with'] !== []) {
            $isBuilder ? $target->with($plan['with']) : $target->load($plan['with']);
        }
        foreach ($plan['counts'] as $relation) {
            $isBuilder ? $target->withCount($relation) : $target->loadCount($relation);
        }
        foreach ($plan['exists'] as $relation) {
            $isBuilder ? $target->withExists($relation) : $target->loadExists($relation);
        }
    }

    /**
     * Which relations to eager-load (and with which columns), withCount, and
     * withExists - derived from the parsed include[] entries.
     *
     * @return array{with: array<int, string>, counts: array<int, string>, exists: array<int, string>}
     */
    private function eagerLoadPlan(): array
    {
        $with = [];
        $counts = [];
        $exists = [];

        foreach ($this->parsed->include as $entry) {
            $field = $entry['field'];

            if ($entry['operator'] === 'count') {
                if ($field->relation) {
                    $counts[] = $field->relation;
                }

                continue;
            }

            if ($entry['operator'] === 'exists') {
                if ($field->relation) {
                    $exists[] = $field->relation;
                }

                continue;
            }

            if ($field->relation === null) {
                continue; // plain column/accessor - nothing to eager-load
            }

            if ($field->eagerLoad !== null) {
                array_push($with, ...$field->eagerLoad);

                continue;
            }

            $attrs = $field->attributesFor($entry['attributes']);

            if ($attrs === []) {
                // No attribute restriction declared - load the relation in full.
                $with[] = $field->relation;

                continue;
            }

            $columns = $attrs;
            foreach ($attrs as $attr) {
                $columns = [...$columns, ...($field->needs[$attr] ?? [])];
            }
            $columns[] = $field->ownerKey ?? $this->joinKeyFor($field->relation);

            $with[] = $field->relation.':'.implode(',', array_values(array_unique($columns)));
        }

        return [
            'with' => array_values(array_unique($with)),
            'counts' => array_values(array_unique($counts)),
            'exists' => array_values(array_unique($exists)),
        ];
    }

    /**
     * The join column Eloquent's relation loader keys its dictionary on for
     * $relationName - the related PK for belongsTo, the FK column otherwise.
     * A trimmed select that omits this column makes the relation silently
     * resolve to null for every row (200 OK, no error) - hence this is always
     * injected into a column-trimmed eager load regardless of what the
     * caller asked for.
     */
    private function joinKeyFor(string $relationName): string
    {
        $relation = app($this->modelClass)->{$relationName}();

        return match (true) {
            method_exists($relation, 'getOwnerKeyName') => $relation->getOwnerKeyName(),
            method_exists($relation, 'getForeignKeyName') => $relation->getForeignKeyName(),
            default => 'id',
        };
    }

    /** Base row (always present) plus everything the parsed include[] asks for. */
    private function row(Model $model): array
    {
        $base = [
            'id' => $model->getAttribute('id'),
            'status' => $model->getAttribute('status'),
            'created_at' => $model->getAttribute('created_at'),
        ];

        foreach ($this->schema->additionalBaseFields as $field) {
            $base[$field] = $model->getAttribute($field);
        }

        return $base + $this->serialize($model);
    }

    /** @return array<string, mixed> */
    private function serialize(Model $model): array
    {
        $data = [];

        foreach ($this->parsed->include as $entry) {
            $field = $entry['field'];

            if ($field->gate && ! ($field->gate)($this->user())) {
                continue;
            }

            $key = match ($entry['operator']) {
                'count' => "{$entry['target']}_count",
                'exists' => "{$entry['target']}_exists",
                default => $entry['target'],
            };

            $value = $field->value($model, $entry['operator'], $entry['attributes']);

            // Omit (rather than null) a *relation* with nothing to show - matches
            // how a request for a nonexistent screenshot/video/author has always
            // behaved. Plain columns/accessors keep the key even when null (e.g.
            // `pin` on a report with no pin yet); count/exists are always scalars.
            if ($value === null && $entry['operator'] === null && $field->relation !== null) {
                continue;
            }

            $data[$key] = $value;
        }

        return $data;
    }

    private function assertParsed(): void
    {
        if ($this->parsed === null) {
            throw new \LogicException('ResourceQuery must be parsed before use - call ->parse() first.');
        }
    }

    private function rejectIfPresent(string $param, array &$errors): void
    {
        if ($this->request->query($param) !== null) {
            $errors[$param] = ["The [{$param}] parameter is not accepted here."];
        }
    }

    // -----------------------------------------------------------------------
    // Group parsers
    // -----------------------------------------------------------------------

    /** @return array<int, array{field: FilterField, target: string, attribute: ?string, operator: string, value: mixed, delegate?: array}> */
    private function parseFilters(array &$errors): array
    {
        $raw = (array) $this->request->query('filter', []);
        $parsed = [];

        foreach ($raw as $key => $value) {
            [$target, $attribute, $operator] = self::splitKey((string) $key);
            $field = $this->schema->filterFor($target, $attribute);

            if (! $field) {
                $errors["filter.{$target}"] = ["Unknown filter target [{$target}]."];

                continue;
            }

            // `attribute === 'id'` is the "bare relation == its primary key"
            // shorthand (handled by $field itself, or by an explicit
            // "target.id" entry) - anything else delegates to the related
            // resource's own schema when this field declares one. See
            // FilterField::$relatedSchema.
            if ($attribute !== 'id' && $field->relatedSchema
                && ! isset($this->schema->filters["{$target}.{$attribute}"])) {
                $entry = $this->parseRelatedFilter((string) $key, $target, $attribute, $operator, $value, $field, $errors);
            } else {
                $entry = $field->parse((string) $key, $target, $attribute, $operator, $value, $errors);
            }

            if ($entry !== null) {
                $parsed[] = $entry;
            }
        }

        return $parsed;
    }

    /**
     * `filter[<target>.<attribute>:<operator>]` where <attribute> isn't one
     * of $target's own explicit entries - validated and applied against the
     * RELATED resource's own filter schema instead, via whereHas(). See
     * FilterField::$relatedSchema for why this exists rather than a
     * $hidden-derived or wide-open attribute surface.
     *
     * @return array{field: FilterField, target: string, attribute: string, operator: string, value: mixed, delegate: array}|null
     */
    private function parseRelatedFilter(string $key, string $target, string $attribute, string $operator, mixed $value, FilterField $ownerField, array &$errors): ?array
    {
        // The exposing resource's allowlist is the security boundary: only
        // attributes it opted in via relatedFilters are reachable, regardless
        // of what the related schema declares for its OWN endpoint. So `email`
        // filterable on the admin-only /user endpoint never becomes filterable
        // through /report's `author` relation (a meta.total oracle to any role).
        if (! in_array($attribute, $ownerField->relatedFilters, true)) {
            $errors["filter.{$target}.{$attribute}"] = ["Unknown attribute [{$attribute}] on filter target [{$target}]."];

            return null;
        }

        $relatedSchema = ($ownerField->relatedSchema)();
        $relatedField = $relatedSchema->filters[$attribute] ?? null;

        if (! $relatedField) {
            $errors["filter.{$target}.{$attribute}"] = ["Unknown attribute [{$attribute}] on filter target [{$target}]."];

            return null;
        }

        $delegate = $relatedField->parse($key, $attribute, null, $operator, $value, $errors);

        if ($delegate === null) {
            return null;
        }

        return [
            'field' => $ownerField,
            'target' => $target,
            'attribute' => $attribute,
            'operator' => $operator,
            'value' => $delegate['value'],
            'delegate' => $delegate,
        ];
    }

    /**
     * Parses filter[]-style keys - `include[]=token`, `include[target]=a,b`,
     * `include[target][]=a`, `include[target.attribute]`, and bare tokens
     * from `include=a,b` - into one entry per (target, operator). Two
     * spellings of the same (target, operator) merge their attribute lists
     * (`include[author]=id&include[author.initials]` -> one `author` entry
     * with `['id','initials']`); a bare request and an explicit field list
     * for the same (target, operator) is a conflict, not a merge, since it's
     * genuinely ambiguous which the caller meant.
     *
     * @return array<int, array{field: IncludeField, target: string, operator: ?string, attributes: array<int, string>}>
     */
    private function parseInclude(array &$errors): array
    {
        $raw = $this->request->query('include');

        if ($raw === null) {
            return [];
        }

        $pairs = $this->normalizeIncludeRaw($raw);

        // Keyed by "target\0operator" so different operators on the same
        // target (e.g. `comments` bare + `comments:count`) stay separate.
        $groups = [];

        foreach ($pairs as [$rawKey, $rawValue]) {
            [$target, $attribute, $operator] = self::splitIncludeKey($rawKey);
            $field = $this->schema->includable[$target] ?? null;

            if (! $field) {
                $errors["include.{$target}"] = ["Unknown include target [{$target}]."];

                continue;
            }

            if ($operator !== null) {
                $allowed = array_map(fn (QueryOperator $op) => $op->value, $field->operators);

                if (! in_array($operator, $allowed, true)) {
                    $errors["include.{$rawKey}"] = ["Operator [{$operator}] is not allowed on include target [{$target}]."];

                    continue;
                }
            }

            $attrs = $this->includeAttributesFrom($rawValue, $target, $errors);

            if ($attrs === null) {
                continue; // invalid field list - already recorded
            }

            if ($attribute !== null) {
                $attrs[] = $attribute;
            }
            $attrs = array_values(array_unique($attrs));

            if ($attrs !== []) {
                if ($field->relation === null || $field->attributes === []) {
                    $errors["include.{$target}"] = ["Include target [{$target}] does not support a field list."];

                    continue;
                }

                $unknownAttrs = array_diff($attrs, $field->attributes);

                if ($unknownAttrs !== []) {
                    $errors["include.{$target}"] = array_map(
                        fn ($a) => "Unknown attribute [{$a}] on include target [{$target}].",
                        array_values($unknownAttrs),
                    );

                    continue;
                }
            }

            $key = $target."\0".($operator ?? '');
            $groups[$key] ??= ['field' => $field, 'target' => $target, 'operator' => $operator, 'sawBare' => false, 'sawExplicit' => false, 'attrs' => []];

            if ($attrs === []) {
                $groups[$key]['sawBare'] = true;
            } else {
                $groups[$key]['sawExplicit'] = true;
                $groups[$key]['attrs'] = array_values(array_unique([...$groups[$key]['attrs'], ...$attrs]));
            }
        }

        $parsed = [];

        foreach ($groups as $key => $group) {
            if ($group['sawBare'] && $group['sawExplicit']) {
                $errors["include.{$group['target']}"] = [
                    "Include target [{$group['target']}] was requested both bare and with a field list - send one or the other.",
                ];

                continue;
            }

            $parsed[] = [
                'field' => $group['field'],
                'target' => $group['target'],
                'operator' => $group['operator'],
                'attributes' => $group['attrs'],
            ];
        }

        return $parsed;
    }

    /**
     * Normalize every accepted include[] wire shape into a flat list of
     * [rawKey, rawValue] pairs - list-form tokens (`include[]=title`) become
     * [token, ''], map-form entries (`include[author]=id,name`) become
     * [author, 'id,name'] as-is, and a bare comma string (`include=a,b`)
     * splits into one pair per token.
     *
     * @return array<int, array{0: string, 1: mixed}>
     */
    private function normalizeIncludeRaw(mixed $raw): array
    {
        if (! is_array($raw)) {
            return array_map(fn ($token) => [trim((string) $token), ''], explode(',', (string) $raw));
        }

        $pairs = [];

        foreach ($raw as $key => $value) {
            $pairs[] = is_int($key) ? [(string) $value, ''] : [(string) $key, $value];
        }

        return $pairs;
    }

    /**
     * Turn one include[] value into an attribute list, or null (having
     * recorded an error) when the value isn't a flat list of scalars -
     * e.g. `include[author][x][y]=1` gives a nested array, which would
     * otherwise silently degrade to the literal string "Array".
     *
     * @return array<int, string>|null
     */
    private function includeAttributesFrom(mixed $rawValue, string $target, array &$errors): ?array
    {
        if ($rawValue === '' || $rawValue === null) {
            return [];
        }

        if (is_array($rawValue)) {
            foreach ($rawValue as $v) {
                if (! is_scalar($v)) {
                    $errors["include.{$target}"] = ["Include target [{$target}]'s field list must be a flat list of attribute names."];

                    return null;
                }
            }

            return array_values(array_map('strval', $rawValue));
        }

        return self::splitList($rawValue);
    }

    /** @return array<int, array{target: string, dir: string}> */
    private function parseSort(array &$errors): array
    {
        $raw = (array) $this->request->query('sort', []);
        $parsed = [];

        foreach ($raw as $target => $dir) {
            $target = (string) $target;

            if (! in_array($target, $this->schema->sortable, true)) {
                $errors["sort.{$target}"] = ["Unknown or non-sortable attribute [{$target}]."];

                continue;
            }

            if (! in_array($dir, ['asc', 'desc'], true)) {
                $errors["sort.{$target}"] = ["Sort direction must be 'asc' or 'desc', got [{$dir}]."];

                continue;
            }

            $parsed[] = ['target' => $target, 'dir' => $dir];
        }

        return $parsed;
    }

    /** @return array{0: int|null, 1: int} [perPage, page] */
    private function parsePaginate(array &$errors): array
    {
        $raw = (array) $this->request->query('paginate', []);
        $known = array_diff(array_keys($raw), ['per_page', 'page']);

        if ($known !== []) {
            foreach ($known as $key) {
                $errors["paginate.{$key}"] = ["Unknown paginate option [{$key}]."];
            }
        }

        // Gate validation on whether per_page was SENT (isset), not on whether
        // it's truthy - `empty($perPage)` was true for both 0 and a
        // non-numeric string cast to 0, so both silently skipped the bounds
        // check and fell through to paginate() with per_page: 0.
        $perPage = self::DEFAULT_PER_PAGE;

        if (isset($raw['per_page'])) {
            $perPage = (int) $raw['per_page'];

            if ($perPage < 1 || $perPage > self::MAX_PER_PAGE) {
                $errors['paginate.per_page'] = ['per_page must be between 1 and '.self::MAX_PER_PAGE.'.'];
                $perPage = self::DEFAULT_PER_PAGE;
            }
        }

        $page = isset($raw['page']) ? (int) $raw['page'] : 1;

        if ($page < 1) {
            $errors['paginate.page'] = ['page must be 1 or greater.'];
            $page = 1;
        }

        return [$perPage, $page];
    }

    /** @return array<int, array{field: AggregateField, target: string, operator: string, value: mixed}> */
    private function parseAggregate(array &$errors): array
    {
        $raw = (array) $this->request->query('aggregate', []);
        $parsed = [];

        foreach ($raw as $key => $value) {
            [$target, , $operator] = self::splitKey((string) $key);
            $field = $this->schema->aggregates[$target] ?? null;

            if (! $field) {
                $errors["aggregate.{$target}"] = ["Unknown aggregate target [{$target}]."];

                continue;
            }

            $entry = $field->parse((string) $key, $target, $operator, $value, $errors);

            if ($entry !== null) {
                $parsed[] = $entry;
            }
        }

        return $parsed;
    }

    /**
     * A second grouping dimension for the grouped-count form of `count`
     * (`aggregate[location:count]&group_by[]=status`) - nests the counts by
     * this column instead of returning one flat total per value. Only one
     * group_by column is supported; it must itself be a plain-column
     * aggregatable target (i.e. usable as a count target on its own).
     */
    private function parseGroupBy(array &$errors): ?string
    {
        $raw = $this->request->query('group_by');

        if ($raw === null) {
            return null;
        }

        $values = is_array($raw) ? array_values($raw) : [(string) $raw];

        if (count($values) > 1) {
            $errors['group_by'] = ['Only one group_by column is supported.'];

            return null;
        }

        $column = $values[0];
        $field = $this->schema->aggregates[$column] ?? null;

        if (! $field || $field->column === null) {
            $errors['group_by'] = ["[{$column}] cannot be used as a group_by column."];

            return null;
        }

        return $column;
    }

    /**
     * Split a `<target>[.<attribute>][:<operator>]` query key. Only the
     * first colon and first dot matter - targets never contain either, so
     * `explode(..., 2)` is safe even for `screenshot.url` or `author.id`.
     *
     * @return array{0: string, 1: string, 2: string}
     */
    private static function splitKey(string $key): array
    {
        if (! str_contains($key, ':')) {
            [$target, $operator] = [$key, 'eq'];
        } else {
            [$target, $operator] = explode(':', $key, 2);
        }

        if (! str_contains($target, '.')) {
            [$target, $attribute] = [$target, 'id'];
        } else {
            [$target, $attribute] = explode('.', $target, 2);
        }

        return [$target, $attribute, $operator];
    }

    /**
     * Same shape as splitKey(), but with include[]-appropriate defaults: no
     * dot means no attribute qualifier (not "id"), no colon means the bare
     * form (not "eq") - filter's defaults are filter-specific and wrong here.
     *
     * @return array{0: string, 1: string|null, 2: string|null}
     */
    private static function splitIncludeKey(string $key): array
    {
        if (str_contains($key, ':')) {
            [$target, $operator] = explode(':', $key, 2);
        } else {
            [$target, $operator] = [$key, null];
        }

        if (str_contains($target, '.')) {
            [$target, $attribute] = explode('.', $target, 2);
        } else {
            [$target, $attribute] = [$target, null];
        }

        return [$target, $attribute, $operator];
    }
}
