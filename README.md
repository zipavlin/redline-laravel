# Redline for Laravel

A Laravel package that adds a floating, on-page bug-reporting widget to your staging
environment. Testers pin comments, annotate screenshots, record video and view existing
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
| Browser | Any modern browser (video recording requires HTTPS or `localhost`) |

---

## Installation

```bash
composer require zipavlin/redline
```

The package uses Laravel **auto-discovery** — no manual registration needed. Publish the
config if you want to adjust it: `php artisan vendor:publish --tag=redline-config`.

---

## Activation

The package is partially gated — know what turns on where:

- **Publishing** and the **artisan commands** (`redline:install`, `redline:user`,
  `redline:clear`) are always available.
- The **dashboard** (SPA at `/{redline.path}`, default `/redline`) loads whenever a
  **local storage provider** is active.
- The **API routes** and **widget injection** are gated by the guard. Default
  `EnvironmentGuardProvider` activates when `APP_ENV=staging` (in the current code
  `APP_DEBUG` does **not** gate the default guard — use `DebugGuardProvider` if you want
  debug-only activation). `REDLINE_GUARD_EXCLUDE` skips injection on matching routes
  (default: no exclusion).

```env
# Minimal staging setup
APP_ENV=staging

# Debug-only activation (alternative guard) — or leave the default staging guard
# REDLINE_GUARD_PROVIDER=Zipavlin\Redline\Providers\Guard\DebugGuardProvider
```

---

## Configuration

All settings are read from `config/redline.php`, which maps to these env variables:

| Variable | Default | Purpose |
|---|---|---|
| `REDLINE_PATH` | `redline` | URL prefix for all routes (`/redline/api/...`, `/redline`) |
| `REDLINE_STORAGE_PROVIDER` | `Zipavlin\Redline\Providers\Storage\DatabaseStorageProvider` | Storage backend class |
| `REDLINE_STORAGE_PATH_BASE` | `redline` | Base dir under `storage/app/` |
| `REDLINE_STORAGE_PATH_SCREENSHOTS` | `screenshots` | Screenshot subdir |
| `REDLINE_STORAGE_PATH_VIDEOS` | `videos` | Video subdir |
| `REDLINE_STORAGE_PATH_DATABASE` | `database` | SQLite file subdir |
| `REDLINE_GUARD_PROVIDER` | `Zipavlin\Redline\Providers\Guard\EnvironmentGuardProvider` | When Redline is active |
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
| `REDLINE_DISABLED` | *(none)* | Comma-separated features to disable: `screenshots, videos, files, comments, mentions` |
| `REDLINE_MAIL` | `true` | Kill switch for all Redline emails |
| `REDLINE_CLOUD_URL` | *(none)* | Remote Redline API URL (cloud backend — planned, not yet implemented) |
| `REDLINE_VITE_URL` | *(none)* | Dev-only: serve the frontend from the Vite dev server (see "Frontend development") |

> Keep the data out of your app's git history:
> `/storage/app/redline/` should be gitignored.

---

## Interactive setup

```bash
php artisan redline:install
```

Walks you through the storage backend, auth provider, mail, and disabled features, writes
your choices to `.env`, publishes config + assets, and for the Database backend creates
the schema.

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
| `ClosedAuthProvider` | No self-registration — admin creates users | Admin assigns |

Notes:

- If no admin exists, the **first developer to register is auto-promoted to admin**.
- With the default `REDLINE_AUTH_ALLOW_SELF_ADMIN=false`, public registration can never
  create an admin — seed the first one with:
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
| Move pins, upload media | own only | ✅ | ✅ |
| Delete reports/comments | own only | own only | ✅ |
| Manage users | ❌ | ❌ | ✅ |

¹ A report owner may only Reopen (resolved → in progress) or Confirm (resolved → done);
developers/admins drive the main lifecycle transitions.

---

## Using the widget

The widget appears as a floating toolbar (bottom-right by default) on every page of the
activated environment. Click any mode button — on first use you are prompted for identity
(name/email/role via the identity modal).

- **Comment / pin (💬)** — click any element to pin a comment; a popup anchored to the
  pin opens. To pin an element that disappears on mouseout (dropdown, tooltip): hover
  until it appears, hold **Alt** (or **Meta**) to freeze the highlight, then click. On
  touch devices, tap the element and confirm with "Pin here?".
- **Screenshot (📷)** — captures the page, then lets you crop and annotate (arrows,
  lines, rectangles, circles, highlighter, text). Capture uses `@zumer/snapdom` (the
  `html-to-image` dependency is transitively pulled by snapdom, not imported directly).
- **Video (🎥)** — records the current tab via `getDisplayMedia` (no audio). Max **2
  minutes**, warning at 1:45 (15 s remaining), auto-stop at 2:00. Requires **HTTPS or
  `localhost`**; the button is hidden where `getDisplayMedia` is unavailable (mobile
  browsers typically lack it).
- **View (👁)** — shows numbered pins for the current URL; click a pin to open its thread
  and reply.
- **Identity chip** — bottom of the toolbar; edit your details or switch identity.

---

## Dashboard

```
https://your-staging-site.com/redline
```

The dashboard is a Vue SPA served by the package at `/{redline.path}` (default
`/redline`); vue-router handles sub-paths such as `/redline/reports`, `/redline/users`
and `/redline/reset-password`. It lists reports (filter by status, search reporter or
URL, show/hide resolved), shows report detail with pin diagram, environment block,
screenshot, video, comment thread, and status controls. Access control is provided by the
same guard/auth as the API — the shell itself loads whenever a local storage provider is
active, and the API enforces auth.

---

## Artisan commands

```bash
# Interactive setup (storage, auth, mail, features) — writes .env, publishes assets
php artisan redline:install

# Create a user / grant admin
php artisan redline:user

# Permanently delete all reports and media (with confirmation)
php artisan redline:clear
```

These are the only commands the package ships.

---

## Storage backends

### Database (default) — `DatabaseStorageProvider`

Eloquent models on a dedicated SQLite connection at `storage/app/redline/redline.db`.
Requires `pdo_sqlite`. Schema (numbered migrations `0001`–`0005`) is created by
`php artisan redline:install`; if the tables are missing at runtime, the provider throws
a `RuntimeException` telling you to run it.

### JSON files — `FileStorageProvider`

One JSON file per report via `ryangjchandler/orbit` (Laravel 12+). Optional dependency —
install with `composer require ryangjchandler/orbit` when you choose this backend.

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
2. In the host app set `REDLINE_VITE_URL=http://localhost` — the blade shells then load
   `src/widget.ts` / `src/dashboard.ts` directly from Vite with hot reload.

Production bundles are built in `redline-app` (`npm run build` → `dist/`), copied into
this repo's `public/` (committed), and published via
`php artisan vendor:publish --tag=redline-assets`.

---

## Testing

```bash
composer test
```

Current baseline: **251 passed, 3 skipped** (the skips are Orbit-gated
`FileDriverGrammarTest` tests that skip when `ryangjchandler/orbit` is not installed).

---

## Changelog

See [CHANGELOG.md](CHANGELOG.md).

## Credits

- [Žiga Pavlin](https://github.com/zipavlin)

## License

The MIT License (MIT). See [LICENSE.md](LICENSE.md).
