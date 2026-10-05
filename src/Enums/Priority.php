<?php

namespace Zipavlin\Redline\Enums;

/**
 * A report's urgency. Unlike {@see Status} this is not a lifecycle: any value
 * is settable at any time, so there is deliberately no transition graph.
 *
 * Declaration order is the ascending severity order used by `sort[priority]`.
 */
enum Priority: string
{
    case LOW = 'low';
    case NORMAL = 'normal';
    case HIGH = 'high';
    case CRITICAL = 'critical';

    /**
     * All backing values, e.g. for validation.
     *
     * @return string[]
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
