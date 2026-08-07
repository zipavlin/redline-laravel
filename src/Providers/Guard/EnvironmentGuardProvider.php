<?php

namespace Zipavlin\Redline\Providers\Guard;

use Illuminate\Http\Request;
use Zipavlin\Redline\Contracts\Listable;

/**
 * Default guard: active only on the staging environment.
 *
 * Both conditions must be met:
 *   - APP_ENV=staging
 */
class EnvironmentGuardProvider extends GuardProvider implements Listable
{
    public function isActive(Request $request): bool
    {
        return app()->environment('staging');
    }

    public static function title(): string
    {
        return 'Environment (active when APP_ENV=staging)';
    }

    public static function position(): int
    {
        return 1;
    }
}
