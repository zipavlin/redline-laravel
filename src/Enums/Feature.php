<?php

namespace Zipavlin\Redline\Enums;

enum Feature: string
{
    // Declaration order is the canonical output order: enabled() filtering,
    // the redline:enable listing and REDLINE_FEATURES writes all follow it.
    case SCREENSHOTS = 'screenshots';
    case VIDEOS = 'videos';
    case FILES = 'files';
    case COMMENTS = 'comments';
    case MCP = 'mcp';

    /**
     * Features explicitly enabled through config('redline.features'). The
     * model is additive: a feature is OFF unless its value appears in the
     * configured list.
     *
     * @return list<Feature> in enum declaration order, duplicates collapsed
     */
    public static function enabled(): array
    {
        $names = (array) config('redline.features', []);

        self::assertKnown($names);

        return array_values(array_filter(
            self::cases(),
            fn (self $feature) => in_array($feature->value, $names, true),
        ));
    }

    /**
     * Reject anything that is not a declared feature value with an error that
     * names the offending value and the valid alternatives - a misconfigured
     * host must fail loudly, never be silently ignored.
     *
     * @param  list<mixed>  $names
     */
    public static function assertKnown(array $names): void
    {
        $unknown = array_values(array_filter(
            $names,
            fn ($name) => ! in_array($name, self::validNames(), true),
        ));

        if ($unknown === []) {
            return;
        }

        throw new \InvalidArgumentException(sprintf(
            'Unknown Redline feature(s): %s. Valid features: %s. Recovery: fix REDLINE_FEATURES in the environment, '
            .'then delete the cached config at bootstrap/cache/config.php (php artisan config:clear cannot run while '
            .'the application fails to boot) - the next boot re-reads the environment.',
            implode(', ', $unknown),
            implode(', ', self::validNames()),
        ));
    }

    /**
     * @return list<string> value strings in enum declaration order
     */
    public static function validNames(): array
    {
        return array_column(self::cases(), 'value');
    }
}
