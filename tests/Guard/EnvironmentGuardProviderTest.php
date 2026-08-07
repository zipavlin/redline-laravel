<?php

use Illuminate\Http\Request;
use Zipavlin\Redline\Providers\Guard\EnvironmentGuardProvider;

// The global TestCase already boots with staging + debug=true,
// so the first test just confirms the default is active.

it('is active when environment is staging and debug is true', function () {
    // Default test environment (set in TestCase::getEnvironmentSetUp)
    expect((new EnvironmentGuardProvider)->isActive(Request::create('/')))->toBeTrue();
});

it('is inactive when environment is not staging', function () {
    $this->app['env'] = 'production';
    expect((new EnvironmentGuardProvider)->isActive(Request::create('/')))->toBeFalse();
    $this->app['env'] = 'staging'; // restore
});

it('stays active on staging regardless of debug', function () {
    // The guard keys off the environment only; debug does not gate it.
    config(['app.debug' => false]);
    expect((new EnvironmentGuardProvider)->isActive(Request::create('/')))->toBeTrue();
    config(['app.debug' => true]); // restore
});

it('is inactive off staging even with debug on', function () {
    $this->app['env'] = 'production';

    expect((new EnvironmentGuardProvider)->isActive(Request::create('/')))->toBeFalse();

    $this->app['env'] = 'staging'; // restore
});
