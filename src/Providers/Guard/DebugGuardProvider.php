<?php

namespace Zipavlin\Redline\Providers\Guard;

use Illuminate\Http\Request;
use Zipavlin\Redline\Contracts\Listable;

/**
 * Default guard: active only where debug enabled.
 *
 * Condition must be met:
 *   - APP_DEBUG=true
 */
class DebugGuardProvider extends GuardProvider implements Listable
{
    public function isActive(Request $request): bool
    {
        return config('app.debug') === true;
    }

    public static function title(): string
    {
        return 'Debug (active when APP_DEBUG=true)';
    }

    public static function position(): int
    {
        return 2;
    }
}
