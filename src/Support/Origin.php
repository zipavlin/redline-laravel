<?php

namespace Zipavlin\Redline\Support;

use Illuminate\Http\Request;

/**
 * Shared Origin allowlist behind double-submit CSRF and the session login gate.
 *
 * A request passes when its Origin header matches the request's own scheme+host
 * (same-origin), or when it is listed in the comma-separated
 * `redline.auth.allowed_origins` escape hatch for reverse-proxy setups.
 *
 * Callers are responsible for the "no Origin header" case: this helper only
 * answers whether a *present* Origin is allowed.
 */
final class Origin
{
    public static function allows(Request $request): bool
    {
        $origin = $request->headers->get('Origin');

        if ($origin === $request->getSchemeAndHttpHost()) {
            return true;
        }

        $configured = config('redline.auth.allowed_origins', null);

        if (! is_string($configured) || trim($configured) === '') {
            return false;
        }

        return in_array($origin, array_map('trim', explode(',', $configured)), true);
    }
}
