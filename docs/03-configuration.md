# Configuration

All Redline settings are read from `config/redline.php`, published to the host app via `php artisan vendor:publish --tag=redline-config`. The published file is the surface you edit; each value is backed by an environment variable that is read at boot, so changing `.env` requires a restart (or `php artisan config:clear`) to take effect.

Two things to keep in mind:

> - The published config is **not** the complete source of truth. Several knobs are read directly via `env()` in package code and never appear in the published file - they are collected in [Env-only variables](#env-only-variables) below.
> - Some comments inside `config/redline.php` are stale. Where a comment and the code disagree, this chapter documents the **code** - see the guard default in the table below and the feature list in [Feature flags](#feature-flags).

## Full environment variable reference

Every key in `config/redline.php` and the env var that backs it:

| Variable | Default | Purpose |
|---|---|---|
| `REDLINE_PATH` | `redline` | URL prefix for all Redline routes (`/redline/api/...` and the dashboard at `/redline`). See [Changing the URL prefix](#changing-the-url-prefix). |
| `REDLINE_CLOUD_URL` | *(none)* | Remote Redline API URL for `CloudStorageProvider`; the widget points at this URL and no local models/routes/files are used. **Planned - the provider is not implemented yet; ignore until a release announces it.** |
| `REDLINE_STORAGE_PROVIDER` | `Zipavlin\Redline\Providers\Storage\DatabaseStorageProvider` | Storage backend class. Must be set explicitly to switch - there is no automatic fallback. See the [Storage chapter](04-storage.md). |
| `REDLINE_STORAGE_PATH_BASE` | `redline` | Base directory under `storage/app/` where Redline stores its data. |
| `REDLINE_STORAGE_PATH_SCREENSHOTS` | `screenshots` | Subdirectory for screenshot uploads. |
| `REDLINE_STORAGE_PATH_VIDEOS` | `videos` | Subdirectory for video uploads. |
| `REDLINE_STORAGE_PATH_DATABASE` | `database` | Subdirectory for the SQLite database file (`redline.db`) used by `DatabaseStorageProvider`. |
| `REDLINE_GUARD_PROVIDER` | `Zipavlin\Redline\Providers\Guard\EnvironmentGuardProvider` | Guard provider class deciding *when* Redline is active. Default is active when `APP_ENV=staging` **only** - the `APP_DEBUG` condition in the config comment is stale, the code checks nothing else. See the [Guards chapter](06-guards.md). |
| `REDLINE_GUARD_EXCLUDE` | *(none)* | Comma-separated paths skipped for widget injection and API. Pattern forms: exact match or any subpath; trailing `/*` excludes subpaths only (parent not excluded); other `*` patterns are `fnmatch` globs. Full semantics in the [Guards chapter](06-guards.md). |
| `REDLINE_AUTH_PROVIDER` | `Zipavlin\Redline\Providers\Auth\OpenAuthProvider` | Auth provider class deciding how users register and claim roles. See the [Authentication chapter](05-authentication.md). |
| `REDLINE_AUTH_TOKEN` | `3600` | JWT TTL in seconds (1 hour). |
| `REDLINE_AUTH_REMEMBER_TOKEN` | `2592000` | JWT TTL in seconds when "Remember me" is selected at login (30 days). |
| `REDLINE_AUTH_GUEST` | `false` | When `true`, `POST /redline/api/report` accepts unauthenticated submissions, attributed to a shared guest user. |
| `REDLINE_AUTH_ALLOW_SELF_ADMIN` | `false` | Security default: public registration can **never** create an admin; seed the first admin via `php artisan redline:user`. Set `true` only on trusted internal networks to let the first developer bootstrap the admin account. |
| `REDLINE_AUTH_PIN_REPORTER` | *(none)* | `PinAuthProvider`: PIN that grants the `reporter` role. |
| `REDLINE_AUTH_PIN_DEVELOPER` | *(none)* | `PinAuthProvider`: PIN that grants the `developer` role. |
| `REDLINE_AUTH_DOMAINS` | *(none)* | `DomainAuthProvider`: comma-separated allowed email domains for registration. |
| `REDLINE_COOKIE_SECURE` | `true` | `Secure` flag on the auth and CSRF cookies. |
| `REDLINE_COOKIE_SAME_SITE` | `lax` | `SameSite` attribute on the auth and CSRF cookies. |
| `REDLINE_COOKIE_DOMAIN` | *(none)* | Cookie domain override (default: host-app behavior). |
| `REDLINE_AUTH_GUEST_ORIGINS` | *(none)* | Comma-separated widget origins allowed to submit guest reports. When set, an anonymous `POST /redline/api/report` must present a matching `Origin` (or `Referer`) header or the request's own scheme+host. |
| `REDLINE_AUTH_GUEST_THROTTLE` | `20` | Per-minute per-IP rate limit for guest report submissions (backs the `redline-guest` limiter). |
| `REDLINE_ALLOWED_ORIGINS` | *(none)* | Escape hatch for cookie-mutation `Origin` checks behind reverse proxies: comma-separated extra origins accepted when the request's scheme+host differs from the browser-visible origin. |
| `REDLINE_DISABLED` | *(none)* | Comma-separated features to disable. All features are on by default. Accepted values are exactly `screenshots, videos, files, comments` - see [Feature flags](#feature-flags). |

Two auth-cookie keys have no env backing and can only be changed by editing the published config: `auth.cookie.name` (default `redline_token`) and the CSRF pair `auth.csrf_cookie` / `auth.csrf_header` (defaults `redline_csrf` / `X-Redline-CSRF`).

## Env-only variables

These knobs are read directly via `env()` in package code and **never appear in the published config**. Set them in `.env`:

| Variable | Default | Purpose |
|---|---|---|
| `REDLINE_MAIL` | `true` | Kill switch for all Redline emails (new-report, password-reset, user-invitation, verify). Parsed with `FILTER_VALIDATE_BOOLEAN`, so `false`, `0`, `off` all disable. Emails are best-effort anyway - see the [Emails chapter](09-emails.md). |
| `REDLINE_AUTH_THROTTLE` | `10` | Per-minute per-IP rate limit on the public auth endpoints (login/register/verify), backing the `redline-auth` limiter. Blunts PIN/password brute-forcing and email enumeration. |
| `REDLINE_BOOTSTRAP_THROTTLE` | `120` | Per-minute per-IP rate limit on the public bootstrap endpoint, backing the `redline-bootstrap` limiter. Generous on purpose: the widget fetches it on every host page load. |
| `REDLINE_GUARD_ALLOWED_IPS` | *(none)* | Comma-separated client-IP allowlist for `Zipavlin\Redline\Providers\Guard\IpGuardProvider` - active only when the client IP intersects the list. Read in the provider itself, so it applies even when `REDLINE_GUARD_PROVIDER` is omitted (default guard ignores it). |
| `REDLINE_VITE_URL` | *(none)* | Dev-only: base URL of the Vite dev server; the package appends `:5173` to it and serves the frontend from there instead of the published assets. Set only in local development. |

## Optional host-side knobs

`config('redline.api.max_rows')` is an opt-in row ceiling for unpaginated index responses. It is **off by default** - omitting `paginate[per_page]` entirely returns every matching row with no bound. To cap that, add a key a host app supplies by editing the published config (it is not present by default):

```php
// config/redline.php (host app)
'api' => [
    'max_rows' => 500, // cap unpaginated index responses at 500 rows
],
```

An explicit `per_page` is unaffected: it is still honored and capped at 100. See the [Resources chapter](07-resources.md) for the full query grammar.

## Cookies & CSRF

The JWT is delivered **exclusively as an HttpOnly cookie** - never in JSON bodies. A second, non-HttpOnly cookie carries the double-submit CSRF value.

- `redline_token` - HttpOnly cookie holding the HS256 JWT (secret = `app.key`, decoded when `base64:`-prefixed). Same-origin dashboard auth. Name changeable only via the published config `auth.cookie.name`.
- `redline_csrf` - non-HttpOnly cookie set alongside the auth cookie. State-changing, cookie-authenticated requests must echo its value in the `X-Redline-CSRF` header (double-submit CSRF).
- Bearer-authenticated requests (cross-origin widget, cloud) **skip CSRF** - the `Authorization: Bearer` header is the proof.
- Both cookies are exempted from Laravel's cookie encryption so middleware and the widget/dashboard JS can read the raw values.

Cookie attributes, all env-backed (see the main table): `Secure` (`REDLINE_COOKIE_SECURE`, default `true`), `SameSite` (`REDLINE_COOKIE_SAME_SITE`, default `lax`), domain (`REDLINE_COOKIE_DOMAIN`, default none), and a hardcoded `path` of `/`.

## Rate limiting

Three named limiters, all per-minute per-IP:

| Limiter | Applied to | Knob | Default |
|---|---|---|---|
| `redline-auth` | Login / register / verify endpoints | `REDLINE_AUTH_THROTTLE` (env-only) | 10/min/IP |
| `redline-guest` | Anonymous `POST /report` submissions (guest mode) | `REDLINE_AUTH_GUEST_THROTTLE` → `redline.auth.guest_throttle` | 20/min/IP |
| `redline-bootstrap` | Public bootstrap endpoint | `REDLINE_BOOTSTRAP_THROTTLE` (env-only) | 120/min/IP |

The guest limiter is enforced inside `RedlineApiAuth` itself; the other two are registered on the route groups.

## Feature flags

`REDLINE_DISABLED` is a comma-separated list of features to turn **off**. All features are on by default.

Accepted values - exactly these four:

- `screenshots`
- `videos`
- `files`
- `comments`

> The `mentions` value listed in a stale comment inside `config/redline.php` does **not** exist - the `Feature` enum defines only the four values above, and unknown strings are silently dropped when the config is parsed.

Example:

```env
REDLINE_DISABLED=videos,files
```

## Changing the URL prefix

`REDLINE_PATH` (default `redline`) controls the URL prefix for every Redline route:

- API: `/redline/api/...` → `/{REDLINE_PATH}/api/...`
- Dashboard shell: `/redline` → `/{REDLINE_PATH}`

## Related

- [Introduction](01-introduction.md)
- [Installation](02-installation.md)
- [Storage](04-storage.md)
- [Authentication](05-authentication.md)
- [Guards](06-guards.md)
- [Resources / API](07-resources.md)
- [Commands](08-commands.md)
- [Emails](09-emails.md)
- [Widget & Dashboard](11-widget-dashboard.md)
