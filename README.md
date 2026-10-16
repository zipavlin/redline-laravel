# Redline for Laravel

A Laravel package for self-hosted, on-page bug-reporting, with a reporting widget and dashboard.  
Testers can pin comments, annotate screenshots, record videos, and view existing
pins without leaving the page; developers review and resolve reports in a built-in
dashboard. All data lives in your own storage (SQLite by default).

[![Latest Version on Packagist](https://img.shields.io/packagist/v/zipavlin/redline.svg?style=flat-square)](https://packagist.org/packages/zipavlin/redline)
[![GitHub Tests Action Status](https://img.shields.io/github/actions/workflow/status/zipavlin/redline-laravel/run-tests.yml?branch=main&label=tests&style=flat-square)](https://github.com/zipavlin/redline-laravel/actions?query=workflow%3Arun-tests+branch%3Amain)
[![Total Downloads](https://img.shields.io/packagist/dt/zipavlin/redline.svg?style=flat-square)](https://packagist.org/packages/zipavlin/redline)

---

## Requirements

| | |
|---|---|
| PHP | 8.3 or 8.4 |
| Laravel | 11.x, 12.x, or 13.x |
| Extension | `pdo_sqlite` (for the default storage backend) |

---

## Get started

```bash
composer require zipavlin/redline
php artisan redline:install
```

Interactive `redline:install` walks you through the storage backend, auth provider, guard,
mail setup, and disabled features, writes your choices to `.env`, publishes config and assets,
and creates a first (admin) user.

---

## Activation

The package is partially gated - know what turns on where:

- **Publishing** and the **artisan commands** (`redline:install`, `redline:update`,
  `redline:user`, `redline:remove`) are always available.
- The **dashboard** (SPA at `/{redline.path}`, default `/redline`) is gated by the guard
- The **API routes** and **widget injection** are also gated by the guard (default when `APP_ENV=staging`)

---

## Configuration

All settings are read from `config/redline.php`, which maps to these env variables:

| Variable | Default | Purpose |
|---|---|---|
| `REDLINE_PATH` | `redline` | URL prefix for all routes (`/redline/api/...`, `/redline`) |
| `REDLINE_STORAGE_PATH_BASE` | `redline` | Base dir under `storage/app/` |
| `REDLINE_STORAGE_PATH_SCREENSHOTS` | `screenshots` | Screenshot subdir |
| `REDLINE_STORAGE_PATH_VIDEOS` | `videos` | Video subdir |
| `REDLINE_STORAGE_PATH_DATABASE` | `database` | SQLite file subdir |
| `REDLINE_GUARD_PROVIDER` | `Zipavlin\Redline\Providers\Guard\EnvironmentGuardProvider` | When Redline is active (`LaravelGuardProvider` = host app user logged in) |
| `REDLINE_GUARD_EXCLUDE` | *(none)* | Comma-separated paths to skip injection on (exact, subpath, `/*`, glob) |
| `REDLINE_AUTH_PROVIDER` | `Zipavlin\Redline\Providers\Auth\OpenAuthProvider` | Registration/role model |
| `REDLINE_AUTH_TOKEN` | `3600` | JWT TTL (seconds) |
| `REDLINE_AUTH_REMEMBER_TOKEN` | `2592000` | "Remember me" JWT TTL (30 days) |
| `REDLINE_AUTH_GUEST` | `false` | Allow anonymous report submission |
| `REDLINE_AUTH_GUEST_ORIGINS` | *(none)* | Comma-separated origins allowed to submit guest reports |
| `REDLINE_AUTH_GUEST_THROTTLE` | `20` | Guest submissions per minute per IP |
| `REDLINE_AUTH_ALLOW_SELF_ADMIN` | `false` | Allow public registration to claim admin (off by default) |
| `REDLINE_AUTH_PIN_REPORTER` | *(none)* | PinAuthProvider: reporter PIN |
| `REDLINE_AUTH_PIN_DEVELOPER` | *(none)* | PinAuthProvider: developer PIN |
| `REDLINE_AUTH_DOMAINS` | *(none)* | DomainAuthProvider: allowed email domains (comma-separated) |
| `REDLINE_AUTH_THROTTLE` | `10` | Auth endpoints rate limit (per minute per IP) |
| `REDLINE_BOOTSTRAP_THROTTLE` | `120` | Bootstrap endpoint rate limit (per minute per IP) |
| `REDLINE_COOKIE_SECURE` | `true` | `Secure` flag on auth/CSRF cookies |
| `REDLINE_COOKIE_SAME_SITE` | `lax` | `SameSite` on auth/CSRF cookies |
| `REDLINE_COOKIE_DOMAIN` | *(none)* | Cookie domain override |
| `REDLINE_ALLOWED_ORIGINS` | *(none)* | Escape hatch for cookie-mutation Origin checks (reverse proxies) |
| `REDLINE_FEATURES` | *(none)* | Comma-separated features to enable (additive - all off until listed): `screenshots, videos, files, comments, mcp` |
| `REDLINE_MAIL` | `true` | Kill switch for all Redline emails |
| `REDLINE_VITE_URL` | *(none)* | Dev-only: serve the frontend from the Vite dev server (see "Frontend development") |

> Keep the data out of your app's git history:
> `/storage/app/redline/` should be gitignored.

---

## Auth providers & roles

Roles are **`reporter` / `developer` / `admin`** (plus a shared `guest` identity for
guest-mode submissions). How roles are claimed depends on the auth provider:

| Provider | How users get in | Role selection |
|---|---|---|
| `OpenAuthProvider` (default) | Anyone can register | Free choice (admin excluded unless `REDLINE_AUTH_ALLOW_SELF_ADMIN=true`) |
| `PinAuthProvider` | Anyone can register with a PIN | PIN decides: reporter PIN → reporter, developer PIN → developer; role selector hidden |
| `DomainAuthProvider` | Registration limited to `REDLINE_AUTH_DOMAINS` (+ email verification) | Provider-defined |
| `ApprovalAuthProvider` | Self-register, then admin approves | Pending until approved |
| `ClosedAuthProvider` | No self-registration - admin creates users | Admin assigns |
| `LaravelAuthProvider` | Host application session - first login provisions a redline **reporter**; no form (empty-body `POST /auth/login`), `login_mode=session` | Fixed: reporter |

> **WARNING:** `LaravelAuthProvider` trusts the host app's email ownership: anyone who can obtain a host account with a given email inherits the matching redline identity. Do **not** pair it with host registration open to untrusted users.

Notes:

- If no admin exists, the **first developer to register is auto-promoted to admin**.
- With the default `REDLINE_AUTH_ALLOW_SELF_ADMIN=false`, public registration can never
  create an admin - seed the first one with:
  ```bash
  php artisan redline:user
  ```
- Authentication is a JWT (HS256) delivered as an **HttpOnly `redline_token` cookie**.
  State-changing cookie-authenticated requests must echo the non-HttpOnly `redline_csrf`
  cookie back in the `X-Redline-CSRF` header (double-submit CSRF). Cross-origin clients
  use `Authorization: Bearer` and skip CSRF.

### Capabilities by role

| Action | Reporter | Developer | Admin |
|---|---|---|---|
| Submit reports / comment | ✅ | ✅ | ✅ |
| Edit own report/comment body | ✅ | ✅ | ✅ |
| Change report status | limited¹ | ✅ | ✅ |
| Change report priority | limited¹ | ✅ | ✅ |
| Set due date | limited¹ | ✅ | ✅ |
| Move pins, upload media | own only | ✅ | ✅ |
| Delete reports/comments | own only | own only | ✅ |
| Manage users | ❌ | ❌ | ✅ |

¹ A report owner may only Reopen (resolved → in progress) or Confirm (resolved → done),
and may set priority or a due date on their own reports only; developers/admins drive the
main lifecycle transitions and may set priority or a due date on any report.

---

## Using the widget

The widget appears as a floating toolbar (bottom-right by default) on every page of the
activated environment. Click any mode button - on first use you are prompted for identity
(name/email/role via the identity modal).

- **Comment / pin** - click any element to pin a comment
- **Screenshot** - captures the page, then lets you crop and annotate
- **Video** - records the current tab via `getDisplayMedia` (no audio; 2 min max)
- **View** - toggle pin visibility: all / open reports
- **Identity chip** - settings

---

## Dashboard

```
https://your-staging-site.com/redline
```

The dashboard is a Vue SPA served at `/{redline.path}` (default `/redline`).
It lists reports and report details, users and user details and simple analytics dashboard.

---

## MCP server

An MCP (Model Context Protocol) server is exposed at

```
https://your-staging-site.com/redline/api/mcp
```

(any path prefix lives at `/{redline.path}/api/mcp`). It lets AI agents read and write bug
reports through the same permission model the dashboard and REST API use. The wire-level
tool contract (inputs, outputs, error semantics) lives in
[redline-app's `contracts/mcp.md`](https://github.com/zipavlin/redline-app).

### Enabling it

The `mcp` feature is **off until listed** - features are additive:

```bash
php artisan redline:enable            # list all features and state
php artisan redline:enable mcp        # enable; writes REDLINE_FEATURES
php artisan redline:enable --disable=mcp
```

Or directly in `.env`: `REDLINE_FEATURES=... , mcp`. The route exists only while the feature
is enabled; with it off, clients get an ordinary `404` (also true after a `route:cache` -
see §Route caching).

### Tokens - bearer only

Agent access needs a long-lived **`rlm_` API token**, created on the server only (there are
no HTTP token endpoints):

```bash
php artisan redline:mcp                       # interactive wizard (default)
php artisan redline:mcp --user=me@example.com --name="Claude"
php artisan redline:mcp --list --user=me@example.com
php artisan redline:mcp --revoke={id}
php artisan redline:mcp --revoke-all --user=me@example.com
```

The token carries the owner's FULL permissions (no scopes) and is delivered over the
`Authorization: Bearer` header only - never from a cookie or a query string. Its lifecycle:

- `--revoke` / `--revoke-all` - soft revocation; later uses get 401.
- That user's **password change invalidates** every outstanding token.
- A plain **logout does NOT** invalidate it (a token is not a session credential).
- Pending/guest users can never be issued or use a token.

Tokens are **operator-managed with no expiry**: revocation is CLI-only
(`redline:mcp --revoke`), a **password change is the only automatic
invalidation**, and a token **outlives a session** - it survives every logout,
including under `LaravelAuthProvider`. One residual note: that same `rlm_`
bearer works on all protected endpoints, which are **not individually
rate-limited** (only the MCP route is).

The plaintext is shown once at creation. Quoting `redline:mcp`'s warning verbatim:

> This token acts with the user's full permissions - treat it as a password;
> it is NOT invalidated by logout; it IS invalidated by that user's password change.

### Tools

Eight tools ship with model-first snake_case names: `report_list`, `report_get`,
`screenshot_get`, `comment_list`, `user_list` (reads; screenshot files above ~2 MB come back
as metadata instead of an inline image) and `report_create`, `comment_create`,
`report_update_status` (writes). Writes run through the same code path as the HTTP
endpoints, so sanitization, the acceptor assignment and the new-report notifications behave
identically on both surfaces. `user_list` returns the compact roster (id, name, role; the
email joins only for developer tokens) an agent needs to resolve people for member
assignment, under the same visibility as the user list endpoint. There is no
screenshot/video upload tool - attach media through the REST endpoints with the same bearer
token; URLs returned by the reads also work with plain `Authorization: Bearer`.

When filing a report, `report_create` takes an `element` CSS selector and/or a `pin`
position: prefer `element` (a short, unique `document.querySelector` selector, max 512
characters) when you can inspect the DOM, and use `pin` percentages when the issue concerns
an area rather than one specific element.

### Client configuration

Run `php artisan redline:mcp` bare: after issuing the token it prints your
client's ready-to-paste setup block with the real endpoint and token inline
(Claude Code, OpenCode, Codex, PI, Cursor, VS Code and Gemini CLI are
confirmed presets, verified against their documented remote-MCP shapes in
2026-10). The endpoint is always `{app}/redline/api/mcp` - with `APP_URL`
unset the block prints a `<YOUR-APP-URL>` placeholder plus a note instead of
a broken URL.

Every listed client sends the `Authorization` header per request on the
streamable-HTTP transport, which is why the backend's cookie-source-`rlm_`
401 rule matters - none of them ever send `rlm_` via cookies.

Keep the token out of committed files either way - it is a password. For the
env-var style clients (Codex, PI, Cursor, Gemini CLI) the token goes in an
env var only, nothing inline; VS Code's file config never carries the token
at all (an `inputs` promptString supplies it, or its CLI alternative with
`REDLINE_MCP_TOKEN`), and Cursor's `${env:}` header reference carries a
caveat against unresolved values in current builds.

---

## Artisan commands

```bash
# Interactive setup (storage, auth, mail, features) - writes .env, publishes assets
php artisan redline:install

# Upgrade an existing install after a composer update (migrations + assets)
php artisan redline:update

# Create a user / grant admin
php artisan redline:user

# List / enable / disable features (writes REDLINE_FEATURES)
php artisan redline:enable

# Interactive token wizard / create / list / revoke MCP API tokens
php artisan redline:mcp

# Permanently delete all data and remove redline from project
php artisan redline:remove
```

### Route caching

The package supports `php artisan route:cache`: its routes load during the
artisan run, the MCP route included. Three things to know:

- The guard is enforced **per request** across the package's whole API + web +
  MCP surface by the `RedlineActive` middleware (404 on API endpoints and the
  MCP endpoint, 403 on the
  dashboard shell), not at boot - at boot it only ever gated the dashboard
  shell. That is what makes a cache environment-portable: a cache built on
  staging and served on a production host still refuses the public surface
  (plain `404`) on the production host instead of exposing the API there.
- `php artisan optimize` runs `route:cache` in standard deploy scripts, so a
  cache compiled while the feature list, guard or path config differed must be
  re-run after changing any of them (or `redline:update`, which clears the
  caches).
- Enforcement that reads per-request context is never baked into the cache:
  the guard re-check happens in `RedlineActive` on every request, and the `mcp`
  feature re-check happens in `RedlineMcpEnabled` - which is why a feature turned
  OFF after caching still reports a plain `404` on the MCP endpoint instead of
  serving a stale server.

The **MCP route follows the guard** like every other redline surface, and the
registration is wrapped in a route GROUP so the gates lead EVERY shape
laravel/mcp creates for the URI - the POST server route, plus the vendor's
unnamed `GET`/`DELETE` probes whose closures answer `405` themselves and which
carry no other middleware. On a host where the guard is **inactive** the
endpoint answers a bare `404` for all three shapes - with AND without a route
cache - leaving no trace (no rate-limiter side effect, no authentication
attempt, and neither laravel/mcp's `400` header validation nor its `405` probe
is reachable). The `mcp` feature gate rides the same group, so turning the
feature off after a cache was compiled `404`s all three shapes too. While the
`mcp` feature is on and the guard active, the route keeps round-tripping a cache
as before. As before, re-run `route:cache` after changing features/guard/path
config.

### My Redline routes return 404

Work through these in order - the first two cover almost every report:

1. **Is the feature on?** Features are additive, so a fresh install has *none*
   enabled and `{path}/api/mcp` is never registered at boot. Run
   `php artisan redline:enable` to see the state, add `mcp` if it is missing
   (`php artisan redline:enable mcp`), then re-run the route cache.
2. **Stale compiled cache.** Re-run `php artisan route:clear` (or `route:cache` /
   `optimize`) after **any** change to `REDLINE_FEATURES`, `REDLINE_GUARD_PROVIDER`
   or `redline.path` - the cache pins the route set it was built from, and
   `optimize` runs `route:cache` in standard deploy scripts.
3. **Guard reality check.** The default `EnvironmentGuardProvider` is active
   only when `APP_ENV=staging`. On a host where the guard is intentionally
   inactive, redline deliberately answers `404` on **all** surfaces, MCP
   included - that is the guard working as designed, not a broken route. Set
   `REDLINE_GUARD_PROVIDER` if the host is meant to serve redline.
4. **Auth, not the host session, authorises MCP.** Under a session-based guard
   (`LaravelGuardProvider`) the endpoint is authorised per request by the bearer
   `rlm_` token from `php artisan redline:mcp`; an absent host user is not a
   route failure.

---

## Storage

Eloquent models on a dedicated SQLite connection at
`storage/app/redline/database/redline.db`. Requires `pdo_sqlite`. The schema
(numbered migrations `0001`–`0007`) is created by `php artisan redline:install`.


---

## Security considerations

- Widget injection and the API are gated by the guard (`APP_ENV=staging` by default);
  publishing and artisan commands remain available for operational use.
- JWT auth cookie is **HttpOnly** (JS can never read it) and never returned in JSON
  bodies; **double-submit CSRF** via the `redline_csrf` cookie + `X-Redline-CSRF` header.
- Guest reporting is opt-in (`REDLINE_AUTH_GUEST`) with configured-origin checks and a
  per-IP throttle; `REDLINE_ALLOWED_ORIGINS` is the reverse-proxy escape hatch.
- Uploaded files are renamed to `{ulid}.{ext}` (original names discarded) and served
  **only through a controller**, never via public URLs.
- Report/comment bodies are sanitized with `htmlpurifier`.
- Public registration can never create an admin by default (`REDLINE_AUTH_ALLOW_SELF_ADMIN=false`).

---

## Frontend development

All frontend source lives in the sibling **redline-app** repo (Vue 3 + TypeScript +
Vite + Nuxt UI). To develop against this package locally:

1. In `redline-app`: `npm ci && npm run dev` (Vite dev server on `:5173`, serves both
   widget and dashboard entries as ESM).
2. In the host app set `REDLINE_VITE_URL=http://localhost` - the blade shells then load
   `src/widget.ts` / `src/dashboard.ts` directly from Vite with hot reload.

Production bundles are built in `redline-app` (`npm run build` → `dist/`), copied into
this repo's `public/` (committed), and published via
`php artisan vendor:publish --tag=redline-assets`.

---

## Testing

```bash
composer test
```

Current baseline: **699 passed, 0 skipped** (2653 assertions).

---

## Changelog

See [CHANGELOG.md](CHANGELOG.md).

## Credits

- [Žiga Pavlin](https://github.com/zipavlin)

## License

The MIT License (MIT). See [LICENSE.md](LICENSE.md).
