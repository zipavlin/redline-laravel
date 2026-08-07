<?php

namespace Zipavlin\Redline\Providers\Guard;

use Illuminate\Http\Request;
use Zipavlin\Redline\Contracts\Listable;

class IpGuardProvider extends GuardProvider implements Listable
{
    /**
     * {@inheritDoc}
     */
    public function isActive(Request $request): bool
    {
        $allowed_ips = str(env('REDLINE_GUARD_ALLOWED_IPS', ''))
            ->explode(',')
            ->map(fn ($ip) => trim($ip))
            ->filter()
            ->toArray();

        return ! empty(
            array_intersect($request->getClientIps(), $allowed_ips)
        );
    }

    public static function title(): string
    {
        return 'IP (active when client IP is in REDLINE_GUARD_ALLOWED_IPS)';
    }

    public static function position(): int
    {
        return 3;
    }
}
