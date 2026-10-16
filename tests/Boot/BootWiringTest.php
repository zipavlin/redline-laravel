<?php

use Illuminate\Support\Facades\Route;
use Zipavlin\Redline\RedlineServiceProvider;

/**
 * Boot-level wiring (M-2): TestCase::bootRedlineForTests() reproduces the service
 * provider's non-console wiring by hand, so nothing else in the suite exercises the
 * real RedlineServiceProvider::boot() branches. This boots a fresh application with
 * APP_RUNNING_IN_CONSOLE=0 so those branches run for real.
 *
 * Application::runningInConsole() reads the APP_RUNNING_IN_CONSOLE env var, and
 * Illuminate\Support\Env re-reads it live rather than caching the value.
 */
it('loads the real routes through the service provider when not running in console', function () {
    $previous = getenv('APP_RUNNING_IN_CONSOLE');
    putenv('APP_RUNNING_IN_CONSOLE=false');

    try {
        $this->refreshApplication();

        expect($this->app->runningInConsole())->toBeFalse()
            ->and(Route::has('redline.auth.login'))->toBeTrue()
            ->and(Route::has('redline.auth.register'))->toBeTrue();

        $this->getJson('/redline/api/bootstrap')->assertStatus(200);
    } finally {
        $previous === false ? putenv('APP_RUNNING_IN_CONSOLE') : putenv('APP_RUNNING_IN_CONSOLE='.$previous);

        // Restore a normal console application for teardown and later tests.
        $this->refreshApplication();
    }
});

// ---------------------------------------------------------------------------
// Feature config validation
// ---------------------------------------------------------------------------

it('fails fast at register when redline.features contains an unknown value', function () {
    config(['redline.features' => ['screenshots', 'tupo']]);

    $provider = new RedlineServiceProvider($this->app);

    expect(fn () => $provider->register())->toThrow(
        InvalidArgumentException::class,
        'Unknown Redline feature(s): tupo. Valid features: screenshots, videos, files, comments, mcp.',
    );
});
