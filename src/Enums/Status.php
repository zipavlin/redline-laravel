<?php

namespace Zipavlin\Redline\Enums;

enum Status: string
{
    case NEW = 'new';
    case NOTICED = 'noticed';
    case PROGRESS = 'progress';
    case RESOLVED = 'resolved';
    case DONE = 'done';

    /**
     * Statuses where work is still needed.
     *
     * @return self[]
     */
    public static function open(): array
    {
        return [self::NEW, self::NOTICED, self::PROGRESS];
    }

    /**
     * Statuses that are probably done.
     *
     * @return self[]
     */
    public static function closed(): array
    {
        return [self::RESOLVED, self::DONE];
    }

    /**
     * All backing values, e.g. for validation.
     *
     * @return string[]
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }

    /**
     * Backing values of the "open" group.
     *
     * @return string[]
     */
    public static function openValues(): array
    {
        return array_map(fn (self $s) => $s->value, self::open());
    }

    /**
     * Backing values of the "closed" group.
     *
     * @return string[]
     */
    public static function closedValues(): array
    {
        return array_map(fn (self $s) => $s->value, self::closed());
    }

    /**
     * Whether the lifecycle permits moving directly from this status to $to.
     *
     * The chain is bidirectional and only adjacent steps are allowed, except
     * that `progress` may be skipped (noticed <-> resolved):
     *
     *   new <-> noticed <-> progress <-> resolved <-> done
     *                  \___ noticed <-> resolved ___/
     */
    public function canTransitionTo(self $to): bool
    {
        $edges = [
            [self::NEW, self::NOTICED],
            [self::NOTICED, self::PROGRESS],
            [self::PROGRESS, self::RESOLVED],
            [self::RESOLVED, self::DONE],
            [self::NOTICED, self::RESOLVED], // skip progress
        ];

        foreach ($edges as [$a, $b]) {
            if (($this === $a && $to === $b) || ($this === $b && $to === $a)) {
                return true;
            }
        }

        return false;
    }
}
