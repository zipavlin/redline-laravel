# AGENTS.md - Redline (Laravel backend)

Composer package **`zipavlin/redline`** (public repo `github.com/zipavlin/redline-laravel`) -
the Laravel backend for Redline: a floating bug-reporting widget + review dashboard for
staging environments. Not a standalone app; installs into a host Laravel app. Frontend
source lives in the sibling **redline-app** repo (see "Related repositories").

## Activation model (do not oversimplify this)

The package is **not** "completely inert outside staging". Only parts of it are gated:

- **Always registered**, regardless of environment: `vendor:publish` tags
  (`redline-assets` → `public/vendor/redline`, `redline-config` → `config/redline.php`,
  `redline-mail-views` → `resources/views/vendor/redline/mail`) and the 3 artisan commands
  (`redline:install`, `redline:user`).
- **Loaded whenever a local storage provider is active**: the dashboard shell
  (`routes/web.php` catch-all serving the SPA at `/{redline.path}`, default `/redline`).
- **Gated by the guard**: the API routes (`routes/api.php`) and widget injection
  (`ShowRedlineWidget` middleware, appended to the `web` group). Default
  `EnvironmentGuardProvider` is active when `APP_ENV=staging` (in the current code
  `APP_DEBUG` does **not** gate it - see `tests/Guard/EnvironmentGuardProviderTest.php`).
  `DebugGuardProvider` gates on `APP_DEBUG=true` instead. `REDLINE_GUARD_EXCLUDE` skips
  injection on matching paths (config default `null` - no exclusion; patterns support
  exact, subpath, `/*` and glob forms).

## Commands

### PHP (Pest)
```
composer test                    # full Pest suite (baseline: 251 passed, 3 skipped)
composer test-coverage           # Pest + coverage
composer format                  # pint (Laravel defaults; no pint.json)
vendor/bin/pest --filter=Report  # single file / group
vendor/bin/pint --dirty          # format only changed files
vendor/bin/pint --test           # dry-run check, exits non-zero on drift
composer prepare                 # testbench package:discover (also runs post-autoload-dump)
```

### Combined verification order
```
vendor/bin/pint --dirty && composer test
```

## Test status (current baseline)

`vendor/bin/pest` reports **251 passed, 3 skipped**. The 3 skipped tests are
`tests/Models/FileDriverGrammarTest.php` - Orbit-gated, they **skip gracefully** when
`ryangjchandler/orbit` is not installed. Never assume Orbit is available.

## Architecture

### Storage providers - `src/Providers/Storage/`

Configured via `REDLINE_STORAGE_PROVIDER` / `config('redline.storage.provider')`.

| Provider | Backing | Notes |
|---|---|---|
| `DatabaseStorageProvider` (default) | Eloquent + own SQLite connection at `storage/app/redline/redline.db` | Requires `pdo_sqlite`. Numbered migrations `database/redline/0001_…0005_`. **Not** auto-migrated at runtime: for a file database it throws a `RuntimeException` telling you to run `php artisan redline:install` when tables are missing. In-memory test databases migrate automatically. |
| `FileStorageProvider` | JSON files via `ryangjchandler/orbit` | **Optional** `suggest` in composer.json - not installed in the dev env, hence the skips. Requires Laravel 12+. |

### Query grammar - `src/Http/Query/`

Generic `filter[]/include[]/sort[]/paginate[]/aggregate[]/group_by[]` REST grammar.
Entry point: `ResourceQuery::from($model, $request)`. Each model defines `querySchema()`
on the Base class (`src/Models/Base/`); see the docblock on `QuerySchema` for the full
reference.

- `FilterField` - declarative column/relation filters; relation filters delegate to the
  related resource's own schema (security boundary: allowlisted via `relatedFilters`).
- `IncludeField` - column/relation/attributes/needs/gate/resolver; auto-derives eager
  loads + join keys.
- `AggregateField` - count/unique/exists with optional `group_by[]`.
- `ParsedQuery` - immutable result; controllers call `->rows()` / `->aggregate()` /
  `->statusCounts()`.

### Guard & auth providers (pluggable)

- **Guard** (`src/Providers/Guard/`): `EnvironmentGuardProvider` (default; `APP_ENV=staging`),
  `DebugGuardProvider` (`APP_DEBUG=true`), or custom. `REDLINE_GUARD_EXCLUDE` skips
  injection on matching routes (default `null`).
- **Auth** (`src/Providers/Auth/`): `OpenAuthProvider` (default; anyone can register, role
  freely selectable), `PinAuthProvider` (PIN per role: `REDLINE_AUTH_PIN_REPORTER` /
  `REDLINE_AUTH_PIN_DEVELOPER`), `DomainAuthProvider` (`REDLINE_AUTH_DOMAINS`),
  `ApprovalAuthProvider` (self-register → pending → admin approves),
  `ClosedAuthProvider` (admin creates users only), `CloudAuthProvider` (cloud-managed
  auth; used with the future cloud storage).

### Auth transport

- JWT via `firebase/php-jwt`, HS256, secret = `config('app.key')` (decoded if
  `base64:`-prefixed). TTLs: `REDLINE_AUTH_TOKEN` (default 3600s),
  `REDLINE_AUTH_REMEMBER_TOKEN` (default 2592000s).
- The token is delivered **exclusively as an HttpOnly cookie** `redline_token` - never in
  JSON bodies. A non-HttpOnly `redline_csrf` cookie is set alongside; state-changing
  cookie-authenticated requests must echo it in the `X-Redline-CSRF` header
  (double-submit CSRF). Bearer-authenticated requests (cross-origin/cloud) skip CSRF.
  Cookie attrs: `REDLINE_COOKIE_SECURE` (default `true`), `REDLINE_COOKIE_SAME_SITE`
  (default `lax`), `REDLINE_COOKIE_DOMAIN`.
- `token_version` bump invalidates all outstanding JWTs (logout / password change).
- Guest mode: `REDLINE_AUTH_GUEST` (default `false`), `REDLINE_AUTH_GUEST_ORIGINS`
  (configured-origin check), `REDLINE_AUTH_GUEST_THROTTLE` (default 20/min per IP),
  `REDLINE_ALLOWED_ORIGINS` escape hatch for reverse-proxy setups.
- `REDLINE_AUTH_ALLOW_SELF_ADMIN` default `false` - public registration can never create
  an admin; seed the first admin via `php artisan redline:user`. The first developer to
  register is auto-promoted to admin when no admin exists.
- Rate limiters: `redline-auth` (`REDLINE_AUTH_THROTTLE`, default 10/min/IP),
  `redline-guest`, `redline-bootstrap` (`REDLINE_BOOTSTRAP_THROTTLE`, default 120/min/IP).

### Features & mail

- `REDLINE_DISABLED` - comma-separated list (`screenshots, videos, files, comments,
  mentions`); all on by default.
- Mail: `REDLINE_MAIL` kill switch (default `true`); `mailConfigured()` is true only when
  the default mailer is a real transport (not `log`/`array`/`null`). Mail sends are
  best-effort and never fail a request. Views under `resources/views/mail/`:
  `new-report`, `password-reset`, `user-invitation`, `verify`.

### Bootstrap endpoint

`GET /{path}/api/bootstrap` - public, rate-limited, no auth/CSRF. Returns `version`,
`features`, `capabilities` (guest reporting, auth provider flags: can_login, can_register,
fields, can_select_role, requires_approval, requires_email_verification, mail.configured)
and the CSRF cookie/header names. Capability flags come from bootstrap at mount, **not**
from element attributes.

### Blade shells & assets

- `resources/views/widget.blade.php` - renders `<redline-widget api=… dashboard=… theme=…
  csrf=…>` (theme from the `redline_theme` cookie, default `dark`). The widget script is
  self-contained (CSS compiled inline into the script; no separate CSS link).
- `resources/views/dashboard.blade.php` - full HTML shell rendering
  `<redline-dashboard api=… dashboard=… theme=… csrf=…>`.
- Dev mode (`REDLINE_VITE_URL` set): Vite ESM from `:5173` - `src/widget.ts` /
  `src/dashboard.ts` + `css/redline-dashboard.css`. Production: published assets
  `/vendor/redline/redline.js?v={version}` and
  `/vendor/redline/redline-dashboard.js` + `redline-dashboard.css`; version cache-busting
  via `RedlineServiceProvider::getVersion()` (Composer InstalledVersions).
- Asset handoff: redline-app builds → its `dist/` → committed copies in this repo's
  `public/` (`redline.js`, `redline-dashboard.js`, `redline-dashboard.css`) → published
  via `vendor:publish --tag=redline-assets`.

### Artisan commands (only these exist)

- `redline:install` - interactive setup (storage, auth provider, email, disabled
  features, writes `.env`, publishes config+assets; for `DatabaseStorageProvider` it also
  creates the schema).
- `redline:user` - admin create/grant.
- `redline:remove` - wipe all redline data, removes redline from the project.

`redline:list` and `redline:dev` were removed; do not document them.

## Testing quirks

- Pest helpers in `tests/Pest.php`: `makeReportData()`, `makeUser()`, `makeAuthToken()`,
  `authHeaders()`, `cleanDatabaseStorage()`, `cleanJsonStorage()`,
  `ensureRedlineDirectories()`.
- Each test gets a fresh in-memory SQLite database (Orchestra Testbench;
  `database.connections.redline` pre-set to `:memory:`).
- `tests/TestCase.php` boots with `APP_ENV=staging` + `app.debug=true`, re-wires the
  pluggable providers, routes and views (the real service provider skips that wiring
  under `runningInConsole()`), appends `ShowRedlineWidget` to the `web` group, and
  disables Laravel's built-in CSRF for tests.
- `tests/Models/FileDriverGrammarTest.php` - Orbit-gated, skips gracefully when
  `ryangjchandler/orbit` is not installed.
- `tests/ArchTest.php` (pest-plugin-arch) - global ban on `dd`, `dump`, `ray`.
- JWT signing key in tests is `config('app.key')` (decoded when `base64:`-prefixed).
- `testbench.yaml` is present and **committed** (was gitignored in the legacy monorepo).

## Conventions

- PHP: PSR-4 `Zipavlin\Redline\` → `src/`; tests `Zipavlin\Redline\Tests\` → `tests/`.
- Linting: Pint (Laravel defaults; no custom `pint.json`).
- EditorConfig: LF line endings, 4-space indent, UTF-8.
- Migrations numbered (`0001_`–`0005_`) in `database/redline/`, not timestamped; created
  by `redline:install` for file databases, auto-run for in-memory test DBs.
- Built IIFE bundles in `public/` **are committed**.
- Public assets tag: `php artisan vendor:publish --tag=redline-assets` (also
  `redline-config`, `redline-mail-views`).

## Related repositories

- **redline-app** (public, `github.com/zipavlin/redline-app`) - all frontend source
  (widget + dashboard), Vue 3 + TS + Vite + Nuxt UI. Builds → `dist/`, handoff copies →
  this repo's `public/`. Dev via `REDLINE_VITE_URL` (Vite :5173)

## Code quality - clean-code-guard

Any session that writes or edits code in this workspace - parent or child - runs a
clean-code guard pass over its changes **before presenting, committing, or merging**.

- **Condensed checklist (always applies):**
    - Names reveal intent - no `data`, `result`, `item`, `temp`, `utils`, `handle_*`.
    - Functions ≤ 20 lines, ≤ 4 params, one thing; no boolean-flag args; no output args
      (command/query separation).
    - Comments explain *why*, never *what*; no commented-out code; match neighbor style.
    - Catch only errors you can recover from; never swallow into silent null/empty success.
    - Validate at trust boundaries (external input, request payloads); no defensive checks
      inside the boundary.
    - No speculative abstractions, config flags, or dead code (YAGNI); strip unused
      imports/symbols before delivery.
    - No hardcoded "ok"/fixture returns in production code; never weaken a test to pass.
    - Verify library APIs against the installed version before calling; read the file you
      edit and one neighbor first.
    - No new dependency where the stdlib, installed packages, or a few lines suffice.
    - Refactors preserve observable behavior; bug fixes are separate, flagged changes.
