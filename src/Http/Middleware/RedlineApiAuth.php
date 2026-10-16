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
use Zipavlin\Redline\Enums\Feature;
use Zipavlin\Redline\Enums\Role;
use Zipavlin\Redline\Models\Token;
use Zipavlin\Redline\Models\User;

/**
 * Verifies Redline credentials on every protected API route.
 *
 * Two token shapes dispatch on the Authorization: Bearer header ONLY:
 *   - `rlm_...` long-lived MCP API tokens (hashed, redline_tokens table);
 *   - anything else is treated as a JWT.
 * The redline_token cookie stays a same-origin fallback for the JWT shape; the
 * query string is never accepted either way (it leaks into logs/Referer), and
 * an `rlm_` cookie value is explicitly rejected - API tokens are bearer-only.
 *
 * The JWT path verifies the HS256 signature, expiry, and per-user
 * token_version; the API-token path compares the stored hash and the user's
 * password epoch (a password change invalidates, a logout does not), refuses
 * revoked tokens and pending/guest users. Both resolve the authenticated user
 * for controllers via $request->user().
 *
 * Guest mode exception: POST /report is allowed through unauthenticated when
 * redline.auth.guest is enabled - attributed to the shared guest user. Such
 * submissions are additionally gated by the redline.auth.guest_origins Origin
 * allowlist and the `redline-guest` rate limiter.
 *
 * Feature enforcement for the MCP endpoint is per-request and lives in
 * RedlineMcpEnabled, which sits in the MCP route GROUP beside the guard so the
 * check covers every shape laravel/mcp registers (the POST server route AND the
 * vendor's 405 GET/DELETE): a route cache compiled while `mcp` was on still
 * reports a plain 404 on a host where the feature is now off.
 *
 * On success the request attribute `redline_auth_source` is set to 'bearer' or
 * 'cookie' so downstream middleware (RedlineCsrf) can enforce double-submit
 * CSRF on cookie-authenticated state-changing requests.
 */
class RedlineApiAuth
{
    public function handle(Request $request, Closure $next): Response
    {
        [$token, $source] = $this->extractToken($request);

        if ($token !== null) {
            return $this->authenticate($request, $token, $source, $next);
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
     * Shape dispatch: an `rlm_` value from the Bearer header is an MCP API
     * token; everything else follows the JWT logic byte-for-byte compatible
     * (JWTs start `eyJ`). A cookie can never carry an API token.
     */
    private function authenticate(Request $request, string $token, string $source, Closure $next): Response
    {
        if ($source === 'cookie' && str_starts_with($token, 'rlm_')) {
            // API tokens are bearer-header only (see the MCP contract): one
            // arriving through the cookie path is an explicit cross-surface
            // probe, so it is rejected - never silently treated as a JWT.
            return response()->json(['message' => 'Unauthenticated.'], 401);
        }

        if ($source === 'bearer' && str_starts_with($token, 'rlm_')) {
            return $this->authenticateApiToken($request, $token, $next);
        }

        return $this->authenticateJwt($request, $token, $source, $next);
    }

    /**
     * The JWT path, preserved as it was before API-token dispatch existed.
     */
    private function authenticateJwt(Request $request, string $token, string $source, Closure $next): Response
    {
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

        // A pending (not yet approved) account may not act at all. Rejecting
        // here closes every protected endpoint in one place - including the
        // admin-only approve/reset-password routes the account must not reach.
        if (! $user->isApproved) {
            return response()->json(['message' => 'Unauthenticated.'], 401);
        }

        // Expose the verified payload so controllers (e.g. refresh) never have
        // to trust an unverified base64 decode of the token.
        $request->attributes->set('redline_token_payload', $payload);

        // Record how this request was authenticated (bearer header or cookie)
        // so downstream middleware (RedlineCsrf) can enforce cookie-only CSRF
        // on state-changing calls.
        $request->attributes->set('redline_auth_source', $source);
        $request->setUserResolver(fn () => $user);

        return $next($request);
    }

    /**
     * The `rlm_` API-token path: hash-lookup, then refuse missing/revoked
     * rows, a password epoch that no longer matches the snapshot taken at
     * creation, pending accounts, and the shared guest identity. On success
     * the user resolver is set, `redline_auth_source` is 'bearer', and the
     * token's last-used fields are recorded.
     */
    private function authenticateApiToken(Request $request, string $plaintext, Closure $next): Response
    {
        $token = app(Token::class)
            ->where('token_hash', Token::hash($plaintext))
            ->first();

        if ($token === null || $token->isRevoked()) {
            return response()->json(['message' => 'Invalid or expired token.'], 401);
        }

        $user = $token->user;

        // The user row is guaranteed by the cascade FK, but a null means the
        // credential resolves to nothing - it must not pass as anonymous.
        if (
            $user === null
            || (int) ($user->password_epoch ?? 0) !== (int) $token->security_epoch
            || ! $user->isApproved
            || $user->isGuest
        ) {
            return response()->json(['message' => 'Unauthenticated.'], 401);
        }

        $token->forceFill([
            'last_used_at' => now(),
            'last_used_ip' => $request->ip(),
        ])->save();

        $request->attributes->set('redline_auth_source', 'bearer');
        $request->setUserResolver(fn () => $user);

        return $next($request);
    }

    /**
     * Extract the token and report where it came from. Bearer precedence is
     * kept so older cached widget bundles that still send an Authorization
     * header keep working; the cookie is the same-origin fallback.
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
