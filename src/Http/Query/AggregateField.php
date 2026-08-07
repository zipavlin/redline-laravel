<?php

namespace Zipavlin\Redline\Http\Query;

use Illuminate\Database\Eloquent\Builder;
use InvalidArgumentException;
use Zipavlin\Redline\Enums\QueryCapability;
use Zipavlin\Redline\Enums\QueryOperator;

/**
 * One aggregatable target in a resource's {@see QuerySchema}, reached via
 * GET /{resource}/aggregate. Two independent operators:
 *
 *   count   With an explicit value list (`aggregate[status:count]=unresolved,resolved`),
 *           counts rows per bucket by re-running the *same* filter semantics used for
 *           `filter[<target>]` - so `status`'s group-vs-concrete resolution applies here
 *           too. Because that reuses the sibling FilterField, the bucketed form is
 *           computed by ResourceQuery rather than here - see ResourceQuery::countBuckets().
 *           Without a value (`aggregate[location:count]`), counts rows grouped
 *           by the plain `$column` instead - that form is self-contained, see compute().
 *   unique  Returns a distinct value set (not a scalar) via $uniqueResolver - e.g. the
 *           distinct authors among rows matching the current filters.
 */
final class AggregateField
{
    /**
     * @param  array<int, QueryOperator>  $operators  QueryOperator::COUNT and/or QueryOperator::UNIQUE
     * @param  string|null  $column  plain DB column, required for the grouped-count form
     * @param  (\Closure(Builder): array)|null  $uniqueResolver  ($baseQuery): distinct value set
     */
    public function __construct(
        public readonly array $operators,
        public readonly ?string $column = null,
        public readonly ?\Closure $uniqueResolver = null,
    ) {
        foreach ($this->operators as $operator) {
            if (! $operator->validFor(QueryCapability::AGGREGATE)) {
                throw new InvalidArgumentException(
                    'AggregateField operators must be QueryOperator::COUNT or QueryOperator::UNIQUE, got '.$operator->name.'.',
                );
            }
        }
    }

    /**
     * Validate one `aggregate[<key>]=<value>` entry.
     *
     * @return array{field: self, target: string, operator: string, value: mixed}|null
     */
    public function parse(string $key, string $target, ?string $operator, mixed $value, array &$errors): ?array
    {
        $allowed = array_map(fn (QueryOperator $op) => $op->value, $this->operators);

        if ($operator === null || ! in_array($operator, $allowed, true)) {
            $errors["aggregate.{$key}"] = ['Aggregate operator must be one of: '.implode(', ', $allowed).'.'];

            return null;
        }

        return ['field' => $this, 'target' => $target, 'operator' => $operator, 'value' => $value === '' ? null : $value];
    }

    /**
     * Compute this aggregate when no explicit value list was given - grouped
     * count (optionally nested by $groupBy) for `count`, or the resolver's
     * distinct value set for `unique`.
     */
    public function compute(Builder $baseQuery, string $operator, ?string $groupBy): mixed
    {
        return match ($operator) {
            'count' => $this->countGrouped($baseQuery, $groupBy),
            'unique' => ($this->uniqueResolver)($baseQuery),
            default => throw new \LogicException("Unhandled aggregate operator [{$operator}]."),
        };
    }

    /**
     * @return array<string, int>|array<string, array<string, int>> flat value => count,
     *                                                              or (when $groupBy is given) value => [secondaryValue => count]
     */
    private function countGrouped(Builder $baseQuery, ?string $groupBy): array
    {
        if ($groupBy === null) {
            return (clone $baseQuery)
                ->select($this->column)
                ->selectRaw('count(*) as aggregate_count')
                ->groupBy($this->column)
                ->pluck('aggregate_count', $this->column)
                ->map(fn ($count) => (int) $count)
                ->all();
        }

        $rows = (clone $baseQuery)
            ->select($this->column, $groupBy)
            ->selectRaw('count(*) as aggregate_count')
            ->groupBy($this->column, $groupBy)
            ->get();

        $nested = [];
        foreach ($rows as $row) {
            $nested[(string) $row->getAttribute($this->column)][(string) $row->getAttribute($groupBy)] = (int) $row->getAttribute('aggregate_count');
        }

        return $nested;
    }
}
