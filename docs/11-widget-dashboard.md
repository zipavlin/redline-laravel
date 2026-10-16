# Widget & Dashboard

> **redline-laravel is the backend/API provider only.** The widget and dashboard UI are built from the existing [Redline app](https://github.com/zipavlin/redline-app) project (Vue 3 + TypeScript + Vite + Nuxt UI). This package ships the built assets and the Blade shells that load them - no frontend source lives here.

## Backend-only package

redline-laravel provides everything the widget and dashboard need to function:

- **Auth** - JWT (HttpOnly cookie) + double-submit CSRF, pluggable auth providers.
- **Storage** - report/comment/user persistence via the configured storage provider.
- **Guard-gated routes** - the REST API and widget injection are only active when the environment guard permits it.
- **Integration shells** - Blade views that render the `<redline-widget>` / `<redline-dashboard>` custom elements and load the JS bundles.

The UI itself comes from [redline-app](https://github.com/zipavlin/redline-app) - the widget (a Vue custom element with Shadow DOM) and the dashboard SPA are built there and shipped here as compiled bundles under `public/`. See [Assets & publishing](#assets--publishing).

## Widget

### Injection

`Zipavlin\Redline\Http\Middleware\ShowRedlineWidget` is appended to the `web` middleware group by `RedlineServiceProvider` - but only when the guard is active and a local storage provider is in use. The middleware injects the widget script just before `</body>` on every response that:

- is permitted by the guard (`shouldShowWidget()`, see [Guards](06-guards.md)) and not excluded via `REDLINE_GUARD_EXCLUDE`;
- is a regular string-body response with `Content-Type: text/html` containing a `</body>` tag.

Binary, streamed, and non-HTML responses pass through unchanged.

### Element contract

The Blade shell `resources/views/widget.blade.php` renders a single custom element:

```html
<redline-widget api="…" dashboard="…" theme="dark" csrf="redline_csrf"></redline-widget>
```

Only transport URLs and the CSRF cookie name are baked into the HTML:

- `api` - the storage provider's API base URL (`apiUrl()`).
- `dashboard` - the dashboard URL (`dashboardUrl()`).
- `theme` - from the `redline_theme` cookie, default `dark`.
- `csrf` - the double-submit CSRF cookie name (`redline.auth.csrf_cookie`, default `redline_csrf`).

Capability flags (features, guest reporting, auth fields/registration, CSRF names) are **not** attributes - the widget fetches them from [bootstrap](#bootstrap-endpoint) at mount. The stylesheet is compiled into the script and injected into the shadow root, so no separate CSS link is needed.

## Dashboard

The dashboard is a Vue SPA served at `/{redline.path}` (default `/redline`) by the catch-all route in `routes/web.php`:

```php
Route::get('{any?}', …)->where('any', '^(?!api(/|$)).*$');
```

The regex excludes the `/api` subtree (handled by `routes/api.php`); every other path falls through to the SPA and `vue-router` routes client-side. The dashboard route is loaded when the guard is active, `shouldShowDashboard()` passes, and the storage provider is local - dashboard availability is independent of widget injection (`shouldShowWidget`).

The shell (`resources/views/dashboard.blade.php`) passes only what the SPA needs synchronously:

- `api` - the API base (`apiUrl()`; may be a different host for the cloud storage provider).
- `base` - the dashboard URL's path component, used as the `vue-router` base.
- `csrf` - the CSRF cookie name.
- `theme` - from the `redline_theme` cookie, default `dark`.

Everything else - feature flags, auth capabilities - comes from bootstrap at mount. A static loader (mirroring the markup created by `redline-app/src/core/dashboard-loader.ts`) renders until the JS boots.

## Assets & publishing

The compiled bundles are committed in this repo's `public/`:

| Asset | Purpose |
|---|---|
| `redline.js` | Self-contained widget (CSS compiled inline). |
| `redline-dashboard.js` | Dashboard SPA. |
| `redline-dashboard.css` | Dashboard styles. |

Publish them into the host app with:

```bash
php artisan vendor:publish --tag=redline-assets
```

…which copies them to `public/vendor/redline/`. Asset URLs get a cache-busting `?v=` query from `RedlineServiceProvider::getVersion()` - the Composer `InstalledVersions` pretty version, or a fresh UUID for dev versions.

## Bootstrap endpoint

`GET /{path}/api/bootstrap` is the public capability manifest both UIs fetch at mount. It is intentionally unauthenticated (no auth, no CSRF): the widget boots on every host page load before any login exists. It is rate-limited via the `redline-bootstrap` limiter (default 120/min/IP).

The payload (`src/Http/Controllers/Api/BootstrapController.php`):

- `version` - manifest version (`2`).
- `features` - enabled features (screenshots/videos/files/comments/mcp).
- `capabilities` - `guest_reporting`, `auth` (`can_login`, `can_register`, `fields`, `can_select_role`, `requires_approval`, `requires_email_verification`), `mail.configured`.
- `csrf` - the `cookie` and `header` names for double-submit CSRF.

No secrets or request data - only capability flags and config names.

## Development mode

All frontend source lives in [redline-app](https://github.com/zipavlin/redline-app). To develop against this package:

1. In `redline-app`: `npm ci && npm run dev` - Vite serves both the widget and dashboard entries as ESM on `:5173`.
2. In the host app, set `REDLINE_VITE_URL` (env-only) - `RedlineServiceProvider::getViteUrl()` appends `:5173`, and the Blade shells then load `src/widget.ts` / `src/dashboard.ts` (plus the Vite client) with hot reload.

Production bundles are built in `redline-app` (`npm run build` → `dist/`), copied into this repo's `public/` (committed), and published via the `redline-assets` tag.

## Related

- [Introduction](01-introduction.md)
- [Configuration](03-configuration.md)
- [Guards](06-guards.md)
- [API / Resources](07-resources.md)
- [Storage](04-storage.md)
- [Authentication](05-authentication.md)
