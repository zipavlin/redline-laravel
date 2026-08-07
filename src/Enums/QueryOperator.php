<?php

namespace Zipavlin\Redline\Enums;

/**
 * The operator vocabulary shared by every query grammar group. Not every
 * operator is meaningful in every group - filter[]'s vocabulary is predicates
 * (eq/like/in/...), aggregate[]'s is projections (count/unique), and include[]
 * accepts a small overlapping subset (count/exists). See validFor().
 */
enum QueryOperator: string
{
    // Predicates - usable in filter[<target>:<op>]=<value>.
    case EQ = 'eq';
    case NOT = 'not';
    case LIKE = 'like';
    case IN = 'in';
    case NOT_IN = 'not_in';
    case FROM = 'from';
    case TO = 'to';
    case GT = 'gt';
    case GTE = 'gte';
    case LT = 'lt';
    case LTE = 'lte';
    case NULL = 'null';
    case NOT_NULL = 'not_null';
    // Dual-purpose - a predicate in filter[] (relationship existence), a
    // projection in include[] (e.g. `comments:exists` -> `comments_exists`).
    case EXISTS = 'exists';
    // Projections - usable in aggregate[<target>:<op>] and include[<target>:<op>].
    case COUNT = 'count';
    case UNIQUE = 'unique';

    /** Every predicate operator - the vocabulary filter[] fields draw from. */
    public static function all(): array
    {
        return array_values(array_diff(self::cases(), [self::COUNT, self::UNIQUE]));
    }

    public static function string(): array
    {
        return [self::EQ, self::NOT, self::LIKE, self::IN, self::NOT_IN, self::NULL, self::NOT_NULL];
    }

    public static function date(): array
    {
        return [self::EQ, self::NOT, self::LIKE, self::FROM, self::TO, self::GT, self::GTE, self::LT, self::LTE, self::NULL, self::NOT_NULL];
    }

    public static function boolean(): array
    {
        return [self::EQ, self::NOT, self::NULL, self::NOT_NULL];
    }

    public static function numeric(): array
    {
        return [self::EQ, self::NOT, self::IN, self::NOT_IN, self::GT, self::GTE, self::LT, self::LTE, self::NULL, self::NOT_NULL];
    }

    /** Operators meaningful against a relationship target (e.g. `author`, `comments`). */
    public static function relation(): array
    {
        return [self::EQ, self::NOT, self::IN, self::NOT_IN, self::EXISTS];
    }

    /** Whether this operator is meaningful within the given query grammar group. */
    public function validFor(QueryCapability $capability): bool
    {
        return match ($capability) {
            QueryCapability::FILTER => $this !== self::COUNT && $this !== self::UNIQUE,
            QueryCapability::INCLUDE => $this === self::COUNT || $this === self::EXISTS,
            QueryCapability::AGGREGATE => $this === self::COUNT || $this === self::UNIQUE,
            QueryCapability::SORT, QueryCapability::PAGINATE, QueryCapability::GROUP => false,
        };
    }
}
