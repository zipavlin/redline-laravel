<?php

namespace Zipavlin\Redline\Http\Query;

/**
 * Redline API query grammar
 * =========================
 * A resource's whitelist for the Redline query grammar - what ResourceQuery
 * validates incoming filter[]/include[]/sort[]/paginate[]/aggregate[] params
 * against, and what it's allowed to execute. Declared once per resource (see
 * Report::querySchema()) and reused for docs/api.md generation rather than
 * hand-maintained.
 *
 * Every collection endpoint accepts the same parameter groups. The shape is always
 * `<group>[<target>[:<operator>]]=<value>`, where `<target>` is an attribute, a
 * relationship, or `relationship.attribute`. Omitting `:<operator>` means equality.
 *
 *   GET /redline/api/report
 *     ?filter[status]=unresolved              // eq (default operator)
 *     &filter[body:like]=There is a bug       // value needs no escaping
 *     &filter[created_at:from]=2026-05-01     // inclusive lower bound
 *     &filter[created_at:to]=2026-07-31       // inclusive upper bound
 *     &filter[author]=<uuid>                  // bare relationship == its primary key
 *     &filter[author.id:in]=<uuid>,<uuid>     // comma-separated OR
 *     &filter[author.name:like]=Zig           // relation attribute - delegates to
 *                                              //   the related resource's OWN schema
 *                                              //   (see FilterField::$relatedSchema);
 *                                              //   NOT every column is reachable this
 *                                              //   way - filter[author.email:like] 422s
 *     &filter[comments:exists]=1              // relationship existence - 1/true or
 *                                              //   0/false only, anything else 422s
 *     &include[]=pin
 *     &include[author]=id,name,initials       // relationship, explicit field list
 *     &include[]=comments:count                // projection -> comments_count
 *     &include[]=screenshot:exists             // projection -> screenshot_exists
 *     &sort[created_at]=desc                  // repeatable; key order = precedence
 *     &paginate[per_page]=16                  // omit entirely for every matching row
 *     &paginate[page]=1
 *
 * GROUPS
 *   filter    Constrain rows. Whitelisted per resource (Report::querySchema()).
 *   include   Add attributes/relationships to *every* row. This is how the widget and
 *             dashboard get different payloads from one endpoint - the widget asks for
 *             `pin,element`, the dashboard for `comments:count,screenshot:exists`. There
 *             is no implicit per-client mode. Base row is always `id`, `status`,
 *             `created_at`; everything else - including `body` and `author` - is
 *             include-gated, same as any other field.
 *   sort      `sort[<attribute>]=asc|desc`. Repeat for multi-key; first key wins ties.
 *             Falls back to the resource's $defaultSort when omitted.
 *   paginate  `paginate[per_page]` and `paginate[page]`. Mirrors the `meta` envelope.
 *             Omitting `per_page` entirely returns every matching row (bounded only by
 *             the optional `config('redline.api.max_rows')` ceiling, off by default) -
 *             an EXPLICIT `per_page` is still capped at 100.
 *
 * OPERATORS
 *   filter[]     eq | not | like | in | not_in | from | to | gt | gte | lt | lte |
 *                null | not_null | exists (relationship existence)
 *   include[]    (bare) | count (-> `<target>_count`) | exists (-> `<target>_exists`)
 *   aggregate[]  count | unique
 *
 * STATUS GROUPS vs CONCRETE STATUSES - the one subtle rule
 *   `filter[status]` accepts either a *group* or a *concrete status*:
 *     unresolved  → every open status   (new, noticed, progress)
 *     resolved    → every closed status (resolved, done)
 *     done        → exactly `done`
 *   So `filter[status]=resolved` is a superset of `filter[status]=done`. Groups are
 *   named in Status::openValues()/closedValues(); concrete values in the Status enum.
 *
 * AGGREGATES live on their own endpoint
 *   GET /redline/api/report/aggregate
 *     ?aggregate[status:count]=unresolved,resolved
 *     &aggregate[location:count]                     // count per distinct value
 *     &aggregate[author:unique]                      // distinct value set, not a scalar
 *     &filter[...]                                   // same filter grammar
 *     &group_by[]=status                             // nest a grouped-count by a 2nd column
 *
 *   Deliberately NOT a parameter on index(), for two reasons:
 *   (1) index()'s response type stays invariant, which generated clients depend on;
 *   (2) aggregates often need *different* filters than the rows - the report tab counts
 *       must ignore `filter[status]` so tabs don't jump as you switch them. A separate
 *       request expresses that by simply omitting the filter.
 *
 *   `group_by[]` only affects the grouped-count form (a `count` with no explicit
 *   value list) - it nests the result by a second column instead of one flat total
 *   per value. `?aggregate[location:count]&group_by[]=status` returns
 *   `{location: {count: {"/a": {"new": 1, "resolved": 1}, "/b": {"new": 1}}}}`,
 *   e.g. for a per-page status breakdown. The group_by column must itself be a
 *   valid plain-column aggregate target.
 *
 * RESPONSES
 *   index      { data: [...], meta: { total } }                                - unpaginated
 *              { data: [...], meta: { total, per_page, current_page, last_page } } - paginated
 *   show       { ...resource }
 *   aggregate  { <target>: { <operator>: <scalar|object|array> } }
 *
 * ERRORS
 *   Unknown or non-whitelisted group/target/operator → 422. Params are never silently
 *   ignored; that is what makes this grammar safe to generate clients from. A group
 *   excluded via ResourceQuery::use() 422s the same way if the caller sends it anyway.
 *
 * KNOWN BOUND
 *   One constraint per (target, operator) pair - `filter[status]=a&filter[status]=b`
 *   keeps only the last. Use `:in` for OR and `:from`/`:to` for ranges. There is also
 *   no cross-target OR: `filter[screenshot:exists]=1&filter[video:exists]=1` means
 *   "has both", not "has either" (two filter[]s are always AND-combined).
 *
 * @see ResourceQuery entry point (<Model>::resourceQuery()), parsing + validation + apply
 * @see FilterField, IncludeField, AggregateField  per-target declarations
 */
final class QuerySchema
{
    /**
     * @param  array<string, FilterField>  $filters  keyed by the public filter target name (e.g. 'status', 'author', 'author.id')
     * @param  array<int, string>  $sortable  public attribute names sortable via sort[<name>]
     * @param  array<string, IncludeField>  $includable  keyed by the public include[] target name (e.g. 'author', 'comments')
     * @param  array<string, AggregateField>  $aggregates  keyed by the public aggregate target name
     * @param  array<string, string>  $defaultSort  column => 'asc'|'desc', applied when the caller sends no sort[] at all
     */
    public function __construct(
        public readonly array $filters,
        public readonly array $sortable,
        public readonly array $includable,
        public readonly array $aggregates = [],
        public readonly array $defaultSort = [],
    ) {}

    /**
     * Look up the FilterField for a `<target>[.<attribute>]` filter key.
     * Tries the fully-qualified `target.attribute` entry first - so a
     * resource can declare per-attribute operators on a relation target
     * (e.g. `author.id` with `:in` allowed where bare `author` only allows
     * `:eq`) - then falls back to the bare `target` entry, which is what
     * makes "bare relation == its primary key" (`filter[author]=<uuid>`) work.
     */
    public function filterFor(string $target, ?string $attribute): ?FilterField
    {
        if ($attribute !== null && isset($this->filters["{$target}.{$attribute}"])) {
            return $this->filters["{$target}.{$attribute}"];
        }

        return $this->filters[$target] ?? null;
    }
}
