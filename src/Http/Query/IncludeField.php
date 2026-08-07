<?php

namespace Zipavlin\Redline\Http\Query;

use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;
use Zipavlin\Redline\Enums\QueryCapability;
use Zipavlin\Redline\Enums\QueryOperator;
use Zipavlin\Redline\Models\Base\User;

/**
 * One include[] target in a resource's {@see QuerySchema} - a plain
 * attribute (`title`), a relation (`author`, `comments`), or a relation
 * reached with a projection operator (`comments:count`, `screenshot:exists`).
 *
 * Two independent things a relation include needs, both to avoid silent
 * wrong data rather than for elegance:
 *   - $needs   accessor dependencies. E.g. `author`'s `initials` reads
 *              User::$name - request `include[author]=initials` without
 *              `name` selected and the accessor 500s on an empty string.
 *   - $ownerKey/auto-derived join key: a trimmed select that omits the
 *              relation's own join column makes Eloquent's relation loader
 *              silently null out every row (200 OK, no error) - see
 *              ResourceQuery::joinKeyFor().
 */
final class IncludeField
{
    /**
     * @param  string|null  $column  plain model attribute to read (covers real columns and
     *                               computed accessors alike - Eloquent's __get handles both) -
     *                               used for the bare/no-relation form.
     * @param  string|null  $relation  Eloquent relation method name, when this include exposes a
     *                                 related model or collection.
     * @param  array<int, QueryOperator>  $operators  non-bare forms allowed on this target - QueryOperator::COUNT
     *                                                and/or QueryOperator::EXISTS. Empty means bare-only.
     * @param  array<int, string>  $attributes  allowed field-list entries for a relation include (e.g.
     *                                          ['id','name','initials']). Empty (with $relation set and no
     *                                          $resolver) means "no field-list support" - load/serialize the
     *                                          whole related model via $eagerLoad.
     * @param  array<int, string>  $default  attributes returned when the caller sends the bare form with no
     *                                       field list (`include[author]` rather than `include[author]=id,name`).
     *                                       Falls back to $attributes (the full set) when empty.
     * @param  array<string, array<int, string>>  $needs  extra columns each attribute requires selected on the
     *                                                    related model beyond itself (accessor dependencies).
     * @param  string|null  $ownerKey  override the auto-derived join key (see ResourceQuery::joinKeyFor()).
     * @param  array<int, string>|null  $eagerLoad  verbatim with()-spec entries for this field, bypassing generic
     *                                              attribute-list derivation - for relations that need a nested
     *                                              eager load (e.g. `comments.user:id,name`) the generic path can't express.
     * @param  (\Closure(?User): bool)|null  $gate  whether this include is available to the current user.
     *                                              Silently omitted (not an error) when false - e.g. `browser`
     *                                              for non-developers.
     * @param  (\Closure(Model, array): mixed)|null  $resolver  custom value computation for the bare form,
     *                                                          overriding the generic column/relation read -
     *                                                          ($model, $attributes): mixed. Non-bare forms
     *                                                          (:count/:exists) are always handled generically,
     *                                                          even when a resolver is set.
     */
    public function __construct(
        public readonly ?string $column = null,
        public readonly ?string $relation = null,
        public readonly array $operators = [],
        public readonly array $attributes = [],
        public readonly array $default = [],
        public readonly array $needs = [],
        public readonly ?string $ownerKey = null,
        public readonly ?array $eagerLoad = null,
        public readonly ?\Closure $gate = null,
        public readonly ?\Closure $resolver = null,
    ) {
        foreach ($this->operators as $operator) {
            if (! $operator->validFor(QueryCapability::INCLUDE)) {
                throw new InvalidArgumentException(
                    'IncludeField operators must be QueryOperator::COUNT or QueryOperator::EXISTS, got '.$operator->name.'.',
                );
            }
        }
    }

    /** Attributes actually in play for one parsed include entry. */
    public function attributesFor(array $requested): array
    {
        if ($requested !== []) {
            return $requested;
        }

        return $this->default !== [] ? $this->default : $this->attributes;
    }

    /**
     * Compute the serialized value for one row. $operator is null for the
     * bare form, 'count'/'exists' for the projection forms (read off the
     * dynamic `{relation}_count`/`{relation}_exists` attribute Eloquent
     * attaches via withCount()/withExists()).
     */
    public function value(Model $model, ?string $operator, array $requested): mixed
    {
        if ($operator === 'count') {
            return (int) ($model->getAttribute("{$this->relation}_count") ?? 0);
        }

        if ($operator === 'exists') {
            return (bool) $model->getAttribute("{$this->relation}_exists");
        }

        if ($this->resolver) {
            return ($this->resolver)($model, $this->attributesFor($requested));
        }

        if ($this->relation) {
            return $this->relationValue($model, $this->attributesFor($requested));
        }

        return $model->getAttribute($this->column);
    }

    private function relationValue(Model $model, array $attrs): mixed
    {
        if (! $model->relationLoaded($this->relation)) {
            return null;
        }

        $related = $model->getRelation($this->relation);

        if ($related instanceof Model) {
            return $this->pluck($related, $attrs);
        }

        if (is_iterable($related)) {
            $rows = collect($related)->map(fn (Model $row) => $this->pluck($row, $attrs))->all();

            return $rows === [] ? null : $rows;
        }

        return null;
    }

    private function pluck(Model $related, array $attrs): array
    {
        $out = [];

        foreach ($attrs as $attr) {
            $out[$attr] = $related->getAttribute($attr);
        }

        return $out;
    }
}
