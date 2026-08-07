<?php

namespace Zipavlin\Redline\Providers\Guard;

use Illuminate\Http\Request;

/**
 * Default guard: active only on the staging environment with debug enabled.
 *
 * Both conditions must be met:
 *   - APP_ENV=staging
 */
class EnvironmentGuardProvider extends GuardProvider
{
    public function isActive(Request $request): bool
    {
        return app()->environment('staging');
    }
}
