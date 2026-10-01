# Middleware

## Overview

The package ships three middleware classes, all under the
`Zipavlin\Redline\Http\Middleware\` namespace:

| Class | Runs where | Job |
|---|---|---|
| `Zipavlin\Redline\Http\Middleware\ShowRedlineWidget` | Laravel's `web` middleware group | Injects the widget script into HTML responses |
| `Zipavlin\Redline\Http\Middleware\RedlineApiAuth` | Protected API route group (`/{redline.path}/api/*`) | Verifies the JWT on every protected API request |
| `Zipavlin\Redline\Http\Middleware\RedlineCsrf` | Protected API route group, **after** `RedlineApiAuth` | Double-submit CSRF for cookie-authenticated state-changing requests |

`ShowRedlineWidget` is the only one registered globally: `RedlineServiceProvider`
appends it to the `web` group (see below), so it runs on **every response that
executes the `web` group**. The two API middleware classes are applied per-route in
`routes/api.php` and never touch non-Redline routes. Because widget injection is
group-based rather than route-based, hosts that serve their own middleware stacks
get it automatically on standard pages but must add it manually elsewhere - see
[Manual injection](#showredlinewidget-widget-injection).

## ShowRedlineWidget (widget injection)

`ShowRedlineWidget` renders the `redline::widget` Blade view (the
`<redline-widget api=… dashboard=… theme=… csrf=…>` custom element plus its
script tag) and splices it into the response just **before the last `</body>`**.
It passes the response through untouched when:

- the guard's `shouldShowWidget()` returns false (see [06-guards](06-guards.md));
- the response is not a regular string-body `Illuminate\Http\Response`
  (binary, streamed, `StreamedResponse`…);
- the `Content-Type` is not `text/html`;
- the body contains no `</body>` tag.

The injected element's `theme` comes from the `redline_theme` cookie (default
`dark`); capability flags (features, guest reporting, auth fields, CSRF names)
are **not** baked into the element - the widget fetches them from
`GET /{redline.path}/api/bootstrap` at mount (see
[11-widget-dashboard](11-widget-dashboard.md)).

### Registration

`RedlineServiceProvider` registers it only when the guard is active.

```php
// src/RedlineServiceProvider.php (boot, non-console requests)
if ($guard->shouldShowWidget(request())) {
    $this->callAfterResolving(Kernel::class, function (Kernel $kernel): void {
        /** @var \Illuminate\Foundation\Http\Kernel $kernel */
        $kernel->appendMiddlewareToGroup('web', ShowRedlineWidget::class);
    });
}
```

So with the default `EnvironmentGuardProvider` (active when `APP_ENV=staging`),
the middleware lands on the `web` group and every HTML page rendered through it
gets the widget. The guard's
`shouldShowWidget()` is **re-evaluated per request** inside the middleware's
`handle()`, so a live environment change (or a `REDLINE_GUARD_EXCLUDE` path
match) immediately stops injection - including when the middleware was added
manually.

### Manual injection

Because the middleware is appended to Laravel's `web` group, the widget only
appears on responses that actually run that group. Host apps or packages that
build their own middleware stacks - for example a Filament admin panel, which
assembles a per-panel middleware list rather than reusing `web` - will **not**
get the widget automatically on those pages.

> The fix is to add the middleware to the stack yourself. The guard and
> `REDLINE_GUARD_EXCLUDE` checks still apply, and the response still has to be
> text/html with a `</body>` tag.

Generic pattern - put the class on the relevant route group or middleware list:

```php
use Zipavlin\Redline\Http\Middleware\ShowRedlineWidget;

Route::middleware([ShowRedlineWidget::class])->group(function () {
    // custom pages that should carry the widget
});
```

Filament example (illustrative - the exact API depends on the installed Filament
version): in a `PanelProvider`, add the middleware to the panel's middleware
list so every page of that panel is injected:

```php
// PanelProvider.php - generic shape only
$panel->middleware([
    \Zipavlin\Redline\Http\Middleware\ShowRedlineWidget::class,
]);
```

## RedlineApiAuth (JWT auth)

`RedlineApiAuth` guards every protected route in `routes/api.php` (the group
declared as `[RedlineApiAuth::class, RedlineCsrf::class]`). It:

- **Extracts the token** from the `Authorization: Bearer` header (used by the
  cross-origin widget) or from the HttpOnly `redline_token` cookie (used by the
  same-origin dashboard). The header takes precedence - kept so older cached
  widget bundles that still send `Authorization` keep working. The query string
  is **never** accepted (it would leak tokens into logs and `Referer`).
- **Verifies** the HS256 signature, expiry, and the `ver` claim against the
  user's `token_version` (`firebase/php-jwt`; signing key is
  `config('app.key')`, base64-decoded when `base64:`-prefixed). A token issued
  before a logout or password change (which bump `token_version`) is rejected,
  as is a token whose user no longer exists.
- **Resolves the user** and exposes it as `$request->user()` for controllers,
  plus the verified payload as the `redline_token_payload` attribute.
- **Records the auth source** as the `redline_auth_source` request attribute -
  `'bearer'` or `'cookie'` - which `RedlineCsrf` reads to decide whether to
  enforce double-submit CSRF.

Failures return `401 {"message": …}`.

### Guest-mode bypass

When `config('redline.auth.guest')` is enabled, an **anonymous** `POST` to the
literal path `{redline.path}/api/report` is allowed through without a token and
attributed to a single shared guest user (`guest@redline.local`), so downstream
code always has `$request->user()`. Guest submissions are additionally gated by:

- `redline.auth.guest_origins` - when configured, the `Origin` header (or
  `Referer` fallback) must match the allowlist or the request's own scheme+host,
  else `403`;
- the `redline-guest` rate limiter (per-minute, per-IP), applied inline via the
  `RateLimiter` facade - over the limit returns `429`.

> Everything else with a missing or invalid token gets a plain `401`.

## RedlineCsrf (double-submit CSRF)

`RedlineCsrf` runs **after** `RedlineApiAuth` inside the protected route group
and only acts when the request was authenticated via the cookie
(`redline_auth_source === 'cookie'`) **and** the method mutates state
(`POST`/`PATCH`/`DELETE`/`PUT`). For those requests it enforces double-submit
CSRF:

- The non-HttpOnly `redline_csrf` cookie's value must be echoed in the
  `X-Redline-CSRF` header, compared with constant-time `hash_equals` - a missing
  or mismatched pair returns `419 {"message": "CSRF token mismatch."}`.
- Defense-in-depth: when an `Origin` header is present, it must equal the
  request's own scheme+host or appear in `redline.auth.allowed_origins`
  (comma-separated) - a foreign `Origin` returns `403` even with a valid token
  in hand, since a browser would never send one.

Bearer-authenticated requests, guest submissions, and safe methods
(`GET`/`HEAD`/`OPTIONS`) pass through untouched.

## Framework middleware used by the API

Besides the package's own classes, `routes/api.php` leans on stock Laravel
middleware for rate limiting and link signing (knobs live in
[03-configuration](03-configuration.md)):

- `throttle:redline-bootstrap` - the public `GET bootstrap` capability manifest,
  so the mount-time fetch can't be hammered (`REDLINE_BOOTSTRAP_THROTTLE`).
- `throttle:redline-auth` - the public auth endpoints
  (register/login/verify/forgot-password/reset-password), to blunt
  brute-force and enumeration (`REDLINE_AUTH_THROTTLE`).
- `signed` - on `POST auth/reset-password`; the signature is what proves the
  reset link is genuine (the email-verification link validates its signature in
  the controller instead, so it can render a friendly page).
- `redline-guest` - not a route-level `throttle:` alias; it is enforced inside
  `RedlineApiAuth` for anonymous report submissions, as described above.

## Related

- [06-guards](06-guards.md) - guard providers, `REDLINE_GUARD_EXCLUDE`, and the
  `shouldShowWidget()`/`shouldShowDashboard()` distinction this chapter relies on
- [03-configuration](03-configuration.md) - rate limiter, cookie, and origin
  configuration keys referenced here
- [05-authentication](05-authentication.md) - JWT issuance, cookie transport,
  and the auth providers the API middleware protects
- [11-widget-dashboard](11-widget-dashboard.md) - the `<redline-widget>` element,
  its attributes, and the bootstrap contract
