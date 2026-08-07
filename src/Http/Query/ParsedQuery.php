<?php

namespace Zipavlin\Redline\Http\Query;

/**
 * A validated, schema-checked query - the output of ResourceQuery::parse().
 * Everything in here has already passed whitelist validation; applying it
 * (ResourceQuery::builder()/aggregate()) assumes so and does no further checking.
 */
final class ParsedQuery
{
    /**
     * @param  array<int, array{field: FilterField, target: string, attribute: ?string, operator: string, value: mixed, delegate?: array{field: FilterField, target: string, attribute: ?string, operator: string, value: mixed}}>  $filters
     * @param  array<int, array{target: string, dir: string}>  $sort
     * @param  array<int, array{field: IncludeField, target: string, operator: ?string, attributes: array<int, string>}>  $include
     * @param  array<int, array{field: AggregateField, target: string, operator: string, value: mixed}>  $aggregate
     */
    public function __construct(
        public readonly array $filters = [],
        public readonly array $sort = [],
        public readonly array $include = [],
        public readonly ?int $perPage = 15,
        public readonly int $page = 1,
        public readonly array $aggregate = [],
        public readonly ?string $groupBy = null,
    ) {}
}
