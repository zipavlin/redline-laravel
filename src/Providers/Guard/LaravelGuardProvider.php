<?php

namespace Zipavlin\Redline\Providers\Guard;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Zipavlin\Redline\Contracts\Listable;

/**
 * Active only when the host Laravel application's user is logged in.
 *
 * Couples Redline's availability to the host app's own session: testers reach the
 * widget and dashboard using the identity they already have, with no separate
 * Redline login (see LaravelAuthProvider).
 */
class LaravelGuardProvider extends GuardProvider implements Listable
{
    public function isActive(Request $request): bool
    {
        return Auth::guard($this->guardName())->check();
    }

    /**
     * Always true: boot-time evaluation cannot see session state, because the
     * session middleware has not run yet at boot. The package must therefore always
     * wire up its routes and middleware; per-request enforcement happens in
     * isActive(), which ShowRedlineWidget and the dashboard shell gate both call.
     */
    public function shouldLoadMiddleware(Request $request): bool
    {
        return true;
    }

    /**
     * The host app guard to resolve the user from. Override in a subclass to use a
     * named guard without touching isActive().
     *
     * NOTE: overriding this on only one of LaravelGuardProvider/LaravelAuthProvider
     * splits widget visibility from exchange identity - keep the two in sync.
     */
    protected function guardName(): ?string
    {
        return null;
    }

    public static function title(): string
    {
        return 'Laravel (active when the host application user is logged in)';
    }

    public static function position(): int
    {
        return 4;
    }
}
