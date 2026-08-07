<?php

namespace Zipavlin\Redline\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Package-owned double-submit CSRF protection for cookie-authenticated requests.
 *
 * Runs AFTER RedlineApiAuth inside the protected route group. When the request
 * was authenticated via the redline_token cookie (redline_auth_source === 'cookie')
 * and the method mutates state (POST/PATCH/DELETE/PUT):
 *
 *   - the non-HttpOnly `redline_csrf` cookie must be echoed back in the
 *     X-Redline-CSRF header (constant-time comparison), otherwise 419;
 *   - an Origin header, when present, must match the request's scheme+host or
 *     redline.auth.allowed_origins, otherwise 403 (defense-in-depth).
 *
 * Bearer-authenticated requests, guest submissions, and safe methods
 * (GET/HEAD/OPTIONS) pass through untouched.
 */
class RedlineCsrf
{
    public function handle(Request $request, Closure $next): Response
    {
        if ($request->attributes->get('redline_auth_source') !== 'cookie') {
            return $next($request);
        }

        if (! in_array($request->getMethod(), ['POST', 'PATCH', 'DELETE', 'PUT'], true)) {
            return $next($request);
        }

        // Defense-in-depth: a browser never sends a cross-origin request whose
        // Origin matches us, so a foreign Origin is grounds for rejection even
        // with a valid CSRF token in hand.
        if ($request->headers->has('Origin') && ! $this->originAllowed($request)) {
            return response()->json(['message' => 'Forbidden origin.'], 403);
        }

        $cookie = $request->cookie(config('redline.auth.csrf_cookie', 'redline_csrf'));
        $header = $request->header(config('redline.auth.csrf_header', 'X-Redline-CSRF'));

        if ($cookie === null || $header === null || ! hash_equals($cookie, $header)) {
            return response()->json(['message' => 'CSRF token mismatch.'], 419);
        }

        return $next($request);
    }

    private function originAllowed(Request $request): bool
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
