<?php

namespace Zipavlin\Redline\Providers\Guard;

use Illuminate\Http\Request;

/**
 * Default guard: active only where debug enabled.
 *
 * Condition must be met:
 *   - APP_DEBUG=true
 */
class DebugGuardProvider extends GuardProvider
{
    public function isActive(Request $request): bool
    {
        return config('app.debug') === true;
    }
}
