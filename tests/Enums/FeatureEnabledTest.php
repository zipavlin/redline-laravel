<?php

use Zipavlin\Redline\Enums\Feature;

// ---------------------------------------------------------------------------
// Feature::enabled() - the single source of truth for "which features are on"
// ---------------------------------------------------------------------------

it('returns no features when none are configured', function () {
    config(['redline.features' => []]);

    expect(Feature::enabled())->toBe([]);
});

it('lists configured features in enum declaration order regardless of config order', function () {
    config(['redline.features' => ['comments', 'screenshots']]);

    expect(Feature::enabled())->toBe([Feature::SCREENSHOTS, Feature::COMMENTS]);
});

it('collapses duplicates in the configured list', function () {
    config(['redline.features' => ['videos', 'videos']]);

    expect(Feature::enabled())->toBe([Feature::VIDEOS]);
});

it('rejects an unknown configured value naming it and the valid ones', function () {
    config(['redline.features' => ['screenshots', 'videoss']]);

    expect(fn () => Feature::enabled())->toThrow(
        InvalidArgumentException::class,
        'Unknown Redline feature(s): videoss. Valid features: screenshots, videos, files, comments, mcp.',
    );
});

it('rejects mentions, which is not a feature case', function () {
    config(['redline.features' => ['screenshots', 'mentions']]);

    expect(fn () => Feature::enabled())->toThrow(
        InvalidArgumentException::class,
        'Unknown Redline feature(s): mentions. Valid features: screenshots, videos, files, comments, mcp.',
    );
});
