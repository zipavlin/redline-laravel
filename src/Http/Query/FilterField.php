<?php

namespace Zipavlin\Redline\Http\Query;

use Illuminate\Database\Eloquent\Builder;
use InvalidArgumentException;
use Zipavlin\Redline\Enums\QueryCapability;
use Zipavlin\Redline\Enums\QueryOperator;

/**
 * One filterable target in a resource's {@see QuerySchema} - e.g. `status`,
 * `body`, or `author`. See the query grammar docblock on ReportController
 * for the wire format this supports.
 */
final class FilterField
{
    /**
     * @param  array<int, QueryOperator>  $operators  predicate operators allowed for this target
     * @param  string|null  $column  actual DB column, when this target maps directly to one
     * @param  string|null  $relation  actual Eloquent relation method, when this target reaches through a relationship
     * @param  string|null  $relationColumn  column on the related model to constrain (defaults to 'id')
     * @param  (\Closure(Builder, string, mixed, ResourceQuery): void)|null  $resolver  custom application that overrides
     *                                                                                  the generic column/relation handling - receives (builder, operator, value, the
     *                                                                                  in-flight ResourceQuery, which exposes ->user() and the static
     *                                                                                  ResourceQuery::splitList() for comma-split values). Used for composite/virtual
     *                                                                                  targets like `search` (OR across two columns), `mine` (needs the current
     *                                                                                  user), and `status` (whereIn after normalize: has already resolved groups to
     *                                                                                  concrete values).
     * @param  (\Closure(mixed, string, array): mixed)|null  $normalize  runs at parse time, before apply() - (value,
     *                                                                   operator, &$errors): normalized value. Lets validation/transformation feed the
     *                                                                   collect-all-errors bag instead of throwing mid-apply (e.g. `status`'s
     *                                                                   group-vs-concrete-value expansion).
     * @param  (\Closure(): QuerySchema)|null  $relatedSchema  lazily returns the related resource's OWN QuerySchema, so
     *                                                         `filter[<target>.<attribute>:<op>]` for an $attribute this
     *                                                         field hasn't declared an explicit "target.attribute" entry
     *                                                         for (see QuerySchema::filterFor()) is validated/applied
     *                                                         against the RELATED model's filters, not left wide open.
     *                                                         A filter is a boolean oracle - no serialization guard
     *                                                         stops `filter[author.email:like]=a%` from leaking every
     *                                                         email one character at a time via `meta.total` - so the
     *                                                         related resource's own schema is the security boundary,
     *                                                         not an afterthought.
     * @param  array<int, string>  $relatedFilters  the allowlist of related attributes reachable through this relation.
     *                                              REQUIRED (non-empty) whenever $relatedSchema is set - the resource
     *                                              exposing the relation owns which of the related resource's filters
     *                                              are reachable through it, so adding a filter to the related schema
     *                                              (e.g. `email` on User for its own admin-only /user endpoint) can
     *                                              never silently widen this surface. Fails safe by construction.
     */
    public function __construct(
        public readonly array $operators = [],
        public readonly ?string $column = null,
        public readonly ?string $relation = null,
        public readonly ?string $relationColumn = null,
        public readonly ?\Closure $resolver = null,
        public readonly ?\Closure $normalize = null,
        public readonly ?\Closure $relatedSchema = null,
        public readonly array $relatedFilters = [],
    ) {
        foreach ($this->operators as $operator) {
            if (! $operator->validFor(QueryCapability::FILTER)) {
                throw new InvalidArgumentException(
                    'FilterField operators must be predicate QueryOperator cases, got '.$operator->name.'.',
                );
            }
        }

        if ($this->relatedSchema !== null && $this->relatedFilters === []) {
            throw new InvalidArgumentException(
                'A FilterField with a relatedSchema must also declare a non-empty relatedFilters allowlist - '
                .'otherwise adding a filter to the related resource silently widens this relation\'s filter surface.',
            );
        }
    }

    /**
     * Validate + normalize one `filter[<key>]=<value>` entry. Returns the
     * parsed descriptor to store on ParsedQuery, or null (having recorded a
     * message into &$errors) when the operator is disallowed or normalize
     * rejects the value.
     *
     * @return array{field: self, target: string, attribute: ?string, operator: string, value: mixed}|null
     */
    public function parse(string $key, string $target, ?string $attribute, string $operator, mixed $value, array &$errors): ?array
    {
        $allowed = array_map(fn (QueryOperator $op) => $op->value, $this->operators);

        if (! in_array($operator, $allowed, true)) {
            $errors["filter.{$key}"] = ["Operator [{$operator}] is not allowed on filter target [{$target}]."];

            return null;
        }

        // A dotted `filter[<target>.<attribute>]` key whose attribute is neither the
        // bare-relation `id` shorthand nor backed by a related schema has nowhere to
        // go: without this, `filter[member.name:eq]=Zoe` would drop `name` and
        // silently apply `Zoe` against the pivot's user_id column instead of 422ing.
        if ($attribute !== null && $attribute !== 'id' && $this->relatedSchema === null) {
            $errors["filter.{$key}"] = ["Filter target [{$target}] does not expose attribute [{$attribute}]."];

            return null;
        }

        // run passed normalize callable
        if ($this->normalize) {
            $before = $errors;
            $value = ($this->normalize)($value, $operator, $errors);

            if ($errors !== $before) {
                return null;
            }
        }
        // or run default 'exists' normalizer - strict truthy/falsy, never a silent no-op
        elseif ($operator === QueryOperator::EXISTS->value) {
            if (self::truthy($value)) {
                $value = true;
            } elseif (self::falsy($value)) {
                $value = false;
            } else {
                $errors["filter.{$key}"] = ["Invalid value [{$value}] for filter[{$target}:exists] - use 1/true or 0/false."];

                return null;
            }
        }

        return ['field' => $this, 'target' => $target, 'attribute' => $attribute, 'operator' => $operator, 'value' => $value];
    }

    /**
     * Apply one parsed filter entry to $builder. $query gives resolvers
     * access to the authenticated user (->user()) and comma-list splitting
     * (->list()) without threading extra parameters through.
     *
     * @param  array{field: self, target: string, attribute: ?string, operator: string, value: mixed}  $parsed
     */
    public function apply(Builder $builder, array $parsed, ResourceQuery $query): void
    {
        if ($this->resolver) {
            ($this->resolver)($builder, $parsed['operator'], $parsed['value'], $query);

            return;
        }

        if ($this->relation) {
            // Existence is a whole-relation predicate (has/doesntHave); the remaining
            // operators constrain a column on the related row (negated for `not`/`not_in`).
            if ($parsed['operator'] === QueryOperator::EXISTS->value) {
                $parsed['value'] ? $builder->has($this->relation) : $builder->doesntHave($this->relation);

                return;
            }

            $relationColumn = $this->relationColumn ?? 'id';

            // Negated operators negate the WHOLE relation predicate ("has no related
            // row matching X"), not the inner column comparison ("has a related row
            // whose column differs from X"). For a to-many relation the latter matches
            // as soon as one row differs, which is almost never the caller's intent.
            $negated = in_array($parsed['operator'], [QueryOperator::NOT->value, QueryOperator::NOT_IN->value], true);
            $operator = match ($parsed['operator']) {
                QueryOperator::NOT->value => QueryOperator::EQ->value,
                QueryOperator::NOT_IN->value => QueryOperator::IN->value,
                default => $parsed['operator'],
            };
            $constrain = function (Builder $inner) use ($operator, $relationColumn, $parsed): void {
                self::applyOperator($inner, $relationColumn, $operator, $parsed['value']);
            };

            if ($negated) {
                $builder->doesntHave($this->relation, 'and', $constrain);
            } else {
                $builder->whereHas($this->relation, $constrain);
            }

            return;
        }

        self::applyOperator($builder, $this->column, $parsed['operator'], $parsed['value']);
    }

    private static function applyOperator(Builder $builder, string $column, string $operator, mixed $value): void
    {
        match ($operator) {
            'eq' => $builder->where($column, $value),
            'not' => $builder->where($column, '!=', $value),
            'like' => $builder->where($column, 'like', '%'.$value.'%'),
            'in' => $builder->whereIn($column, ResourceQuery::splitList($value)),
            'not_in' => $builder->whereNotIn($column, ResourceQuery::splitList($value)),
            'from' => $builder->where($column, '>=', $value),
            'to' => $builder->where($column, '<=', $value),
            'gt' => $builder->where($column, '>', $value),
            'gte' => $builder->where($column, '>=', $value),
            'lt' => $builder->where($column, '<', $value),
            'lte' => $builder->where($column, '<=', $value),
            'null' => $builder->whereNull($column),
            'not_null' => $builder->whereNotNull($column),
            default => throw new \LogicException("Unhandled operator [{$operator}]."),
        };
    }

    public static function truthy(mixed $value): bool
    {
        return in_array($value, ['1', 'true', true, 1], true);
    }

    public static function falsy(mixed $value): bool
    {
        return in_array($value, ['0', 'false', false, 0], true);
    }
}
