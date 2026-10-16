<?php

namespace Zipavlin\Redline\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Zipavlin\Redline\Providers\Guard\GuardProvider;

/**
 * Per-request guard gate for the whole Redline HTTP surface (api + web +
 * mcp).
 *
 * WHY shouldServeRoutes() and not isActive(): this middleware is the guard
 * half of the same per-request layering the dashboard shell uses, and it runs
 * BEFORE the session stack - EncryptCookies/StartSession nest inside the group
 * - so a session is never started yet at this point in the pipeline.
 *
 * The guard exposes three deliberately distinct answers:
 *   - isActive() - "is Redline active for this request" (the raw guard answer).
 *   - shouldServeRoutes() - this middleware's predicate: may this request
 *     reach the package's routes (api + web)? No widget-exclusion coupling -
 *     REDLINE_GUARD_EXCLUDE only skips widget injection, never the surface
 *     itself. Guards that keep the default reduce it to isActive(), so a
 *     route cache built while the guard was active is still denied on a host
 *     where the guard is inactive - per-request enforcement survives a
 *     point-in-time cache crossing environments.
 *   - shouldLoadMiddleware() - the boot-time wiring decision, consulted by
 *     the service provider (it folds in the REDLINE_GUARD_EXCLUDE list via
 *     shouldShowWidget(), and that is correct there: at boot we are deciding
 *     whether to append widget injection, not whether to serve routes).
 *
 *   LaravelGuardProvider (a session host guard) overrides BOTH to always
 *   true, precisely because session state is unavailable before the session
 *   stack; its per-request enforcement lives in isActive() (widget and the
 *   dashboard shell gate), both of which run LATER in the pipeline and stay
 *   exactly as they are. Calling isActive() here would reach for the session
 *   too early and deny every API request - the POST /auth/login session
 *   exchange that LaravelAuthProvider depends on included - with a 404.
 *
 * DENIAL STATUS (middleware parameter, RedlineActive / RedlineActive:403):
 *   - Default 404 for the API groups: with an inactive guard those routes are
 *     simply absent (a bare host without a cache serves nothing there), so 404
 *     keeps today's observable behaviour and does not confirm the endpoints'
 *     existence. A 404 also avoids an existence oracle.
 *   - :403 for the web group: the dashboard shell's documented per-request
 *     denial is a 403 (shouldShowDashboard aborts 403), so the middleware keeps
 *     that contract. The shell's own check stays in place - it is the
 *     dashboard-specific predicate (isActive() && isDashboard()); the
 *     middleware is the guard half of the same per-request layering, not a
 *     duplicate.
 *
 * The MCP route IS wrapped by this middleware, FIRST in its chain: the
 * registration runs inside a route GROUP whose middleware starts with this one,
 * so the guard precedes everything else on every shape laravel/mcp registers
 * (the POST server route plus the vendor's unnamed GET/DELETE 405 shapes) - a
 * host where the guard is inert answers 404 with no authentication attempt and
 * no rate-limiter side effect, with AND without a route cache. The same group
 * carries RedlineMcpEnabled, so the `mcp` feature's per-request 404 covers all
 * three shapes too.
 */
class RedlineActive
{
    public function handle(Request $request, Closure $next, int $status = 404): Response
    {
        if (! app(GuardProvider::class)->shouldServeRoutes($request)) {
            abort($status);
        }

        return $next($request);
    }
}
