<?php

namespace Zipavlin\Redline\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Zipavlin\Redline\Enums\Feature;

/**
 * Per-request `mcp` feature gate for the MCP endpoint.
 *
 * laravel/mcp registers THREE routes on the URI: the POST server route, plus an
 * unnamed GET and DELETE whose closures answer 405 by themselves. Only the POST
 * route carries any other middleware, so a feature check living inside
 * RedlineApiAuth - a POST-shaped middleware - cannot cover the 405 shapes: with
 * a route cache compiled while `mcp` was on, a host whose feature is now off
 * would answer GET/DELETE with the vendor's 405 instead of the plain 404 every
 * other disabled surface gives.
 *
 * Living in the route GROUP, next to RedlineActive, puts one check in front of
 * all three shapes - cache or no cache, whatever `redline.path` was when the
 * cache was compiled. It runs after the guard and before the rate limiters and
 * authentication, so a disabled feature reaches neither a limiter nor a token
 * lookup (zero traces, same as an inactive guard).
 */
class RedlineMcpEnabled
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! in_array(Feature::MCP, Feature::enabled(), true)) {
            return response()->json(['message' => 'Not found.'], 404);
        }

        return $next($request);
    }
}
