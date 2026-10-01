<?php

namespace Zipavlin\Redline\Http\Middleware;

use Closure;
use Firebase\JWT\ExpiredException;
use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use Firebase\JWT\SignatureInvalidException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Symfony\Component\HttpFoundation\Response;
use Zipavlin\Redline\Enums\Role;
use Zipavlin\Redline\Models\User;

/**
 * Verifies the Redline JWT on every protected API route.
 *
 * Extracts the token from the Authorization: Bearer header (cross-origin widget)
 * or the redline_token cookie (same-origin dashboard), verifies the HS256
 * signature, expiry, and per-user token_version, then resolves the authenticated
 * user for controllers via $request->user().
 *
 * Guest mode exception: POST /redline/api/report is allowed through
 * unauthenticated when redline.auth.guest is enabled - attributed to the
 * shared guest user. Such submissions are additionally gated by the
 * redline.auth.guest_origins Origin allowlist and the `redline-guest` rate
 * limiter.
 *
 * On success the request attribute `redline_auth_source` is set to 'bearer'
 * or 'cookie' so downstream middleware (RedlineCsrf) can enforce double-submit
 * CSRF on cookie-authenticated state-changing requests.
 */
class RedlineApiAuth
{
    public function handle(Request $request, Closure $next): Response
    {
        [$token, $source] = $this->extractToken($request);

        if ($token !== null) {
            $payload = $this->verifyToken($token);

            if ($payload === null) {
                return response()->json(['message' => 'Invalid or expired token.'], 401);
            }

            $user = app(User::class)->find($payload->sub);

            // Reject tokens issued before a logout / password change (the
            // token_version bump invalidates every outstanding JWT for the user).
            if ($user === null || (int) ($payload->ver ?? 0) !== (int) ($user->token_version ?? 0)) {
                return response()->json(['message' => 'Unauthenticated.'], 401);
            }

            // Expose the verified payload so controllers (e.g. refresh) never have
            // to trust an unverified base64 decode of the token.
            $request->attributes->set('redline_token_payload', $payload);

            // Record how this request was authenticated so downstream middleware
            // (RedlineCsrf) can enforce cookie-only CSRF on state-changing calls.
            $request->attributes->set('redline_auth_source', $source);
            $request->setUserResolver(fn () => $user);

            return $next($request);
        }

        // No token - allow anonymous report submission in guest mode, attributed
        // to the shared guest user so downstream code always has $request->user().
        if (
            config('redline.auth.guest', false)
            && $request->isMethod('POST')
            && $request->is(config('redline.path').'/api/report')
        ) {
            if (! $this->guestOriginAllowed($request)) {
                return response()->json(['message' => 'Forbidden origin.'], 403);
            }

            if (! $this->guestNotThrottled($request)) {
                return response()->json(['message' => 'Too many requests.'], 429);
            }

            $guest = $this->guestUser();
            $request->setUserResolver(fn () => $guest);

            return $next($request);
        }

        return response()->json(['message' => 'Unauthenticated.'], 401);
    }

    /**
     * Extract the JWT and report where it came from. Bearer precedence is kept so
     * older cached widget bundles that still send an Authorization header keep
     * working; the cookie is the same-origin fallback.
     *
     * @return array{0: ?string, 1: string}
     */
    private function extractToken(Request $request): array
    {
        $header = $request->header('Authorization', '');

        if (str_starts_with($header, 'Bearer ')) {
            return [substr($header, 7), 'bearer'];
        }

        // Same-origin dashboard relies on the JWT cookie; the cross-origin widget
        // always sends the Authorization header. The query string is intentionally
        // NOT accepted (it leaks into logs/Referer).
        return [$request->cookie(config('redline.auth.cookie.name', 'redline_token')), 'cookie'];
    }

    /**
     * Guest report submissions: when redline.auth.guest_origins is configured, the
     * Origin (or Referer fallback) must match the allowlist or the request's own
     * scheme+host. Not configured → no restriction (backward compatible).
     */
    private function guestOriginAllowed(Request $request): bool
    {
        $configured = config('redline.auth.guest_origins', null);

        if (! is_string($configured) || trim($configured) === '') {
            return true;
        }

        $origin = $request->headers->get('Origin') ?? $request->headers->get('Referer');

        if ($origin === null) {
            return false;
        }

        if ($origin === $request->getSchemeAndHttpHost()) {
            return true;
        }

        return in_array($origin, array_map('trim', explode(',', $configured)), true);
    }

    /**
     * Enforce the named `redline-guest` limiter (per-minute, per-IP) for anonymous
     * report submissions. Returns false when the caller is over the limit.
     */
    private function guestNotThrottled(Request $request): bool
    {
        $limiter = RateLimiter::limiter('redline-guest');

        if ($limiter === null) {
            return true;
        }

        $limit = $limiter($request);

        $key = $limit->key ?? (string) $request->ip();

        if (RateLimiter::tooManyAttempts($key, $limit->maxAttempts)) {
            return false;
        }

        RateLimiter::hit($key, $limit->decaySeconds);

        return true;
    }

    /**
     * The single shared guest user all anonymous reports are attributed to.
     */
    private function guestUser(): User
    {
        return app(User::class)->firstOrCreate(
            ['email' => 'guest@redline.local'],
            ['name' => 'Guest', 'role' => Role::GUEST],
        );
    }

    private function verifyToken(string $token): ?\stdClass
    {
        try {
            return JWT::decode($token, new Key($this->signingKey(), 'HS256'));
        } catch (ExpiredException|SignatureInvalidException|\UnexpectedValueException) {
            return null;
        }
    }

    private function signingKey(): string
    {
        $key = config('app.key', '');

        if (str_starts_with($key, 'base64:')) {
            return base64_decode(substr($key, 7));
        }

        return $key;
    }
}
