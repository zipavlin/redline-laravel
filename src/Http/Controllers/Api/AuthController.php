<?php

namespace Zipavlin\Redline\Http\Controllers\Api;

use Firebase\JWT\JWT;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use Illuminate\View\View;
use Zipavlin\Redline\Mail\PasswordResetMail;
use Zipavlin\Redline\Models\User;
use Zipavlin\Redline\Providers\Auth\AuthProvider;
use Zipavlin\Redline\Providers\Storage\StorageProvider;
use Zipavlin\Redline\RedlineServiceProvider;
use Zipavlin\Redline\Support\Origin;

class AuthController extends Controller
{
    public function __construct(protected readonly AuthProvider $auth) {}

    /**
     * POST /redline/api/auth/register
     */
    public function register(Request $request): JsonResponse
    {
        if (! $this->auth->canRegister()) {
            return $this->error(['Registration not allowed.'], 403);
        }

        $errors = $this->auth->validate($request->all());
        if (! empty($errors)) {
            return $this->error($errors, 422);
        }

        $user = $this->auth->register($request->all());

        // New accounts start pending when the provider needs admin approval OR email
        // verification (the account activates once the emailed link is clicked).
        $status = ($this->auth->requiresApproval() || $this->auth->requiresEmailVerification()) ? 'pending' : 'approved';
        if ($user->status !== $status) {
            $user->update(['status' => $status]);
        }

        $this->auth->afterRegister($user);

        if ($user->isPending) {
            return $this->error([$this->auth->pendingMessage()], 202, pending: true);
        }

        return $this->tokenResponse($user);
    }

    /**
     * POST /redline/api/auth/login
     */
    public function login(Request $request): JsonResponse
    {
        if (! $this->auth->canLogin()) {
            return $this->error(['Login not available via this provider.'], 403);
        }

        // A foreign Origin lets another site drive a login on the victim's behalf:
        // in session mode it silently mints a redline session off the victim's host
        // cookie, and in credentials mode the browser's password manager can submit
        // the victim's saved credentials. An absent Origin is a non-browser client - allowed.
        if ($request->headers->has('Origin') && ! Origin::allows($request)) {
            return response()->json(['message' => 'Forbidden origin.'], 403);
        }

        // The body is intentionally ignored in session mode (the host session IS the
        // credential), so do not "fix" this by validating email/password here.
        $result = $this->auth->login($request->only('email', 'password'));

        if (isset($result['error'])) {
            return $this->error([$result['error']], $result['code'] ?? 401);
        }

        if (($result['status'] ?? null) === 'pending') {
            return $this->error([$result['message'] ?? 'Account not yet approved.'], 202, pending: true);
        }

        // Session login is locked to the short-lived 1h token (refreshed by the
        // client); `remember` is ignored because the host session, not the token,
        // decides how long access lasts.
        $remember = $this->auth->loginMode() === 'session'
            ? false
            : $request->boolean('remember', $request->boolean('remember_me', false));

        return $this->tokenResponse($result['user'], $remember);
    }

    /**
     * GET /redline/api/auth/verify
     *
     * Signature is validated here (not via the `signed` middleware) so a browser
     * following an invalid/expired link gets a friendly HTML page instead of
     * Laravel's 403 page. API clients keep the JSON envelope.
     */
    public function verify(Request $request): JsonResponse|View
    {
        if (! $request->hasValidSignature()) {
            return $this->verifyFailure($request, 'Invalid or expired verification link.', 422);
        }

        $result = $this->auth->verify($request->all());

        if (isset($result['error'])) {
            return $this->verifyFailure($request, $result['error'], 422);
        }

        if (! isset($result['user'])) {
            return $this->verifyFailure($request, 'Verification not supported by this provider.', 404);
        }

        $this->auth->afterVerify($result['user']);

        if ($request->wantsJson()) {
            return $this->tokenResponse($result['user']);
        }

        return view('redline::verify-success', ['dashboardUrl' => app(StorageProvider::class)->dashboardUrl()]);
    }

    /**
     * Shared failure path for the verify endpoint: JSON envelope for API clients
     * (`{ errors: [...], pending: bool }`, matching `error()`), friendly HTML
     * page for browsers.
     */
    protected function verifyFailure(Request $request, string $message, int $code): JsonResponse|View
    {
        if ($request->wantsJson()) {
            return $this->error([$message], $code);
        }

        return view('redline::verify-invalid', ['dashboardUrl' => app(StorageProvider::class)->dashboardUrl()]);
    }

    /**
     * GET /redline/api/auth/me (protected)
     */
    public function me(Request $request): JsonResponse
    {
        return response()->json(['user' => $this->authUser($request)->toArray()]);
    }

    /**
     * GET /redline/api/auth/refresh (protected)
     */
    public function refresh(Request $request): JsonResponse
    {
        // Read `remember` from the signature-verified payload attached by
        // RedlineApiAuth - never from an unverified base64 decode the client controls.
        $payload = $request->attributes->get('redline_token_payload');

        return $this->tokenResponse($this->authUser($request), (bool) ($payload->remember ?? false));
    }

    /**
     * POST /redline/api/auth/forgot-password
     *
     * Emails a signed reset link. Always returns 200 (no account enumeration).
     * The link points at the dashboard reset page carrying the signed query for
     * the reset-password endpoint.
     */
    public function forgotPassword(Request $request): JsonResponse
    {
        $request->validate(['email' => ['required', 'email']]);

        $user = app(User::class)->whereEmail($request->input('email'))->first();

        if ($user) {
            $signed = URL::temporarySignedRoute(
                'redline.auth.reset-password',
                now()->addHour(),
                ['email' => $user->email],
            );

            // Email links to the dashboard reset page carrying the SAME signed query;
            // the SPA re-POSTs it to the reset endpoint, so the signature (generated
            // for that endpoint) still validates.
            $query = parse_url($signed, PHP_URL_QUERY);
            $base = rtrim(app(StorageProvider::class)->dashboardUrl(), '/');

            RedlineServiceProvider::mail($user->email, new PasswordResetMail($user->name, $base.'/reset-password?'.$query));
        }

        return response()->json(['ok' => true]);
    }

    /**
     * POST /redline/api/auth/reset-password (signed)
     *
     * The `signed` middleware validates the link; sets a new password and revokes
     * outstanding tokens.
     */
    public function resetPassword(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string', 'min:8'],
        ]);

        $user = app(User::class)->whereEmail($validated['email'])->first();

        if (! $user) {
            return $this->error(['Invalid or expired reset link.'], 422);
        }

        $user->update(['password' => Hash::make($validated['password'])]);
        $user->bumpTokenVersion();

        return response()->json(['ok' => true]);
    }

    /**
     * DELETE /redline/api/auth (protected)
     */
    public function logout(Request $request): JsonResponse
    {
        // Bump token_version so every JWT issued before this logout is rejected.
        $this->authUser($request)->bumpTokenVersion();

        // Expire BOTH the auth cookie and the CSRF cookie with the exact attributes
        // they were issued with (Path, Domain, Secure, HttpOnly, SameSite) so the
        // browser actually deletes them regardless of the live cookie's attributes.
        $path = config('redline.auth.cookie.path', '/');
        $domain = config('redline.auth.cookie.domain');
        $secure = $this->cookieSecure();
        $sameSite = config('redline.auth.cookie.same_site', 'lax');

        $forget = function (string $name) use ($path, $domain, $secure, $sameSite) {
            // Deletion cookie: null value + past expiry makes the browser drop the cookie.
            return cookie()->make($name, null, -2000, $path, $domain, $secure, true, false, $sameSite); // @phpstan-ignore argument.type
        };

        return response()->json(['message' => 'Logged out.'])
            ->withCookie($forget(config('redline.auth.cookie.name', 'redline_token')))
            ->withCookie($forget(config('redline.auth.csrf_cookie', 'redline_csrf')));
    }

    // ─── Helpers ────────────────────────────────────────────────────────────────

    /**
     * Success response for the register/login/verify/refresh flows: issue a JWT,
     * set the HttpOnly `redline_token` cookie plus the non-HttpOnly `redline_csrf`
     * cookie (double-submit CSRF), and return `{ user }` only - the JWT is never
     * exposed in JSON bodies.
     */
    protected function tokenResponse(User $user, bool $remember = false): JsonResponse
    {
        $token = $this->issueToken($user, $remember);

        $ttl = $this->tokenTtl($token);
        $name = config('redline.auth.cookie.name', 'redline_token');
        $path = config('redline.auth.cookie.path', '/');
        $domain = config('redline.auth.cookie.domain');
        $secure = $this->cookieSecure();
        $sameSite = config('redline.auth.cookie.same_site', 'lax');

        $response = response()->json(['user' => $user->toArray()])
            // HttpOnly=true - the JWT is never readable by JS (P2 fix).
            ->withCookie(cookie($name, $token, $ttl, $path, $domain, $secure, true, false, $sameSite));

        // Double-submit CSRF token: HttpOnly=false so the widget/dashboard JS can
        // read it and echo it back in the X-Redline-CSRF header (P1 fix).
        $response->headers->setCookie(cookie(
            config('redline.auth.csrf_cookie', 'redline_csrf'),
            Str::random(32),
            $ttl,
            $path,
            $domain,
            $secure,
            false,
            false,
            $sameSite
        ));

        return $response;
    }

    /**
     * Uniform error/pending envelope (`{ errors: [...], pending: bool }`). `errors`
     * may be EITHER a flat list of strings OR a field-keyed map `{ field: [messages] }`
     * (e.g. register's validation errors) - the frontend flattens whichever shape it
     * receives for display.
     */
    protected function error(array $errors, int $code, bool $pending = false): JsonResponse
    {
        return response()->json(['errors' => $errors, 'pending' => $pending], $code);
    }

    protected function issueToken(User $user, bool $remember = false): string
    {
        $key = config('app.key', '');

        if (str_starts_with($key, 'base64:')) {
            $key = base64_decode(substr($key, 7));
        }

        $now = time();
        $ttl = $remember
            ? (int) config('redline.auth.remember_token', 2592000)
            : (int) config('redline.auth.token', 3600);

        return JWT::encode([
            'sub' => $user->id,
            'ver' => (int) ($user->token_version ?? 0),
            'role' => $user->role->value,
            'name' => $user->name,
            'email' => $user->email,
            'remember' => $remember,
            'iat' => $now,
            'exp' => $now + $ttl,
        ], $key, 'HS256');
    }

    /**
     * Whether the auth cookie is flagged Secure (HTTPS-only). Defaults to true;
     * set REDLINE_COOKIE_SECURE=false only for local plain-HTTP development.
     *
     * Read through config so the host app's published config can override the env
     * default (env fallback is preserved via config/redline.php).
     */
    protected function cookieSecure(): bool
    {
        return filter_var(config('redline.auth.cookie.secure', true), FILTER_VALIDATE_BOOLEAN);
    }

    private function tokenTtl(string $token): int
    {
        $payload = json_decode(base64_decode(explode('.', $token)[1] ?? ''), true);

        return (int) ceil(max(0, ($payload['exp'] ?? 0) - time()) / 60);
    }
}
