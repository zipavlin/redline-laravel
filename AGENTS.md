# AGENTS.md - Redline (Laravel backend)

Composer package **`zipavlin/redline`** (public repo `github.com/zipavlin/redline-laravel`) -
the Laravel backend for Redline: a floating bug-reporting widget + review dashboard for
staging environments. Not a standalone app; installs into a host Laravel app. Frontend
source lives in the sibling **redline-app** repo (see "Related repositories").

## Activation model (do not oversimplify this)

The package is **not** "completely inert outside staging". Only parts of it are gated:

- **Always registered**, regardless of environment: `vendor:publish` tags
  (`redline-assets` → `public/vendor/redline`, `redline-config` → `config/redline.php`,
  `redline-mail-views` → `resources/views/vendor/redline/mail`) and the 4 artisan commands
  (`redline:install`, `redline:update`, `redline:user`, `redline:remove`).
- **Loaded whenever a local storage provider is active**: the dashboard shell
  (`routes/web.php` catch-all serving the SPA at `/{redline.path}`, default `/redline`).
- **Gated by the guard**: the API routes (`routes/api.php`) and widget injection
  (`ShowRedlineWidget` middleware, appended to the `web` group). Default
  `EnvironmentGuardProvider` is active when `APP_ENV=staging` (in the current code
  `APP_DEBUG` does **not** gate it - see `tests/Guard/EnvironmentGuardProviderTest.php`).
  `DebugGuardProvider` gates on `APP_DEBUG=true` instead. `LaravelGuardProvider` is
  active when the host application user is logged in - it overrides
  `shouldLoadMiddleware()` to always wire up, because the session is not available at
  boot; per-request enforcement happens in `isActive()` (widget) and the 403 gate in
  `routes/web.php` (dashboard). `REDLINE_GUARD_EXCLUDE` skips
  injection on matching paths (config default `null` - no exclusion; patterns support
  exact, subpath, `/*` and glob forms).

## Commands

### PHP (Pest)
```
composer test                    # full Pest suite (baseline: 503 passed, 0 skipped; see Test status)
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

`vendor/bin/pest` reports **503 passed, 0 skipped (1625 assertions)**. The package has a
single storage backend, so there are no environment-dependent skips.

## Architecture

### Storage - `src/Providers/Storage/`

The package has a single local storage backend: a concrete `StorageProvider`
(Eloquent models on a dedicated SQLite connection). There is no multi-provider
config and no custom-provider seam.

| Provider | Backing | Notes |
|---|---|---|
| `StorageProvider` | Eloquent + own SQLite connection at `storage/app/redline/database/redline.db` | Requires `pdo_sqlite`. Numbered migrations `database/redline/0001_…0009_`. **Not** auto-migrated at runtime: for a file database boot only verifies the file exists and `redline_reports` is present (a `RuntimeException` pointing at `php artisan redline:install` otherwise). It does **not** check per-migration drift - keeping an installed schema current is `redline:update`'s job, wired as a composer `post-package-update` listener (`Zipavlin\Redline\ComposerHook`, `REDLINE_SKIP_UPDATE_HOOK` opts out). In-memory test databases migrate automatically. |

### Query grammar - `src/Http/Query/`

Generic `filter[]/include[]/sort[]/paginate[]/aggregate[]/group_by[]` REST grammar.
Entry point: `ResourceQuery::from($model, $request)`. Each model defines `querySchema()`
in `src/Models/`; see the docblock on `QuerySchema` for the full reference.

- `FilterField` - declarative column/relation filters; relation filters delegate to the
  related resource's own schema (security boundary: allowlisted via `relatedFilters`).
- `IncludeField` - column/relation/attributes/needs/gate/resolver; auto-derives eager
  loads + join keys.
- `AggregateField` - count/unique/exists with optional `group_by[]`.
- `ParsedQuery` - immutable result; controllers call `->rows()` / `->aggregate()` /
  `->statusCounts()`.

### Report members - `redline_members` pivot

Many-to-many report ↔ user members live in a plain `belongsToMany` pivot
(`redline_members`) with **no model of its own** - `Report::members()` points
at `User`. Migration `0006_create_redline_members_table.php` declares a
composite primary key `(report_id, user_id)` (the pair's uniqueness) and real foreign
keys with `CASCADE` (consistent with `0003`–`0005`): `report_id` → `redline_reports.id`
and `user_id` → `redline_users.id`. Cleanup is therefore delegated to the database:
`ReportController::destroy()` and `UserController::destroy()` leave members alone, and
`MemberController::store()` relies on `syncWithoutDetaching` for its additive +
idempotent behaviour.

Endpoints (inside the protected `RedlineApiAuth` + `RedlineCsrf` group):

| Method | Path | Behaviour |
|---|---|---|
| POST | `/report/{report}/member` | body `{"user_ids": [...]}`; `Operation::UPDATE`-gated (developer/admin/author); additive + idempotent (`syncWithoutDetaching`); unknown ids → 422 |
| DELETE | `/report/{report}/member` | body `{"user_ids": [...]}`; `Operation::UPDATE`-gated (developer/admin/author) **or** an eligible (approved, non-guest) caller removing exactly themselves; idempotent (`detach`); removes only existing pairs |

Both respond `{ "members": [ { id, name, initials } ] }` - the report's full current
list, sorted by `name` then `id` (the shared `Support\Members::fromUsers()` projection,
also used by the read grammar so the two cannot drift).

Read grammar on `Report::querySchema()`: include target `members` (relation
`members`; bare / `:count` / `:exists` → `members_count`/`members_exists`) and filter
target `member` (relation operators over `members.id`). `filter[member:not]` /
`filter[member:not_in]` negate the **whole** membership predicate ("has no such
member"), not an inner column comparison. `filter[member.name:like]` is **not**
supported (no `relatedSchema` on that filter) and 422s, as intended.

### Guard & auth providers (pluggable)

- **Guard** (`src/Providers/Guard/`): `EnvironmentGuardProvider` (default; `APP_ENV=staging`),
  `DebugGuardProvider` (`APP_DEBUG=true`), `LaravelGuardProvider` (host application user
  logged in; always wires up at boot, enforced per request), or custom.
  `REDLINE_GUARD_EXCLUDE` skips injection on matching routes (default `null`).
- **Auth** (`src/Providers/Auth/`): `OpenAuthProvider` (default; anyone can register, role
  freely selectable), `PinAuthProvider` (PIN per role: `REDLINE_AUTH_PIN_REPORTER` /
  `REDLINE_AUTH_PIN_DEVELOPER`), `DomainAuthProvider` (`REDLINE_AUTH_DOMAINS`),
  `ApprovalAuthProvider` (self-register → pending → admin approves),
  `ClosedAuthProvider` (admin creates users only), `LaravelAuthProvider` (host app session
  exchange on `POST /auth/login` with an empty body; no redline forms; first login
  provisions a fixed-role reporter; `login_mode=session` in bootstrap),
  `CloudAuthProvider` (cloud-managed auth; used with the future cloud storage).

  > **WARNING:** `LaravelAuthProvider` trusts the host app's email ownership - anyone
  > who can obtain a host account with a given email inherits the matching redline
  > identity. Do not pair it with host registration open to untrusted users.

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

- `redline:install` - interactive setup (auth provider, email, disabled
  features, writes `.env`, publishes config+assets, adds the composer
  `post-package-update` hook to the host composer.json; it also creates the
  SQLite schema).
- `redline:update` (alias `redline:upgrade`) - upgrade an installed Redline: run
  pending package migrations, re-publish assets, offer config/mail re-publish
  and the composer `post-package-update` hook (neither clobbered under `--force`);
  flags `--force`, `--dry-run`, `--no-backup`.
- `redline:user` - create/edit/promote users directly in storage (create blocked under `LaravelAuthProvider`); flags `--promote`, `--edit` (mutually exclusive).
- `redline:remove` - wipe all redline data (including the composer hook entry), removes redline from the project.

The host-side `post-package-update` listener lives in `src/ComposerHook.php` and the
composer.json writer shared by all three commands in `src/Support/ComposerScripts.php`.
`ComposerHook` duck-types the Composer event (`object $event`, never a `Composer\Script\*`
type hint) because `composer/composer` is not installed here; it runs
`php artisan redline:update --force` through `symfony/process` and never fails
`composer update`.

`redline:list` and `redline:dev` were removed; do not document them.

## Testing quirks

- Pest helpers in `tests/Pest.php`: `makeReportData()`, `makeUser()`, `makeAuthToken()`,
  `authHeaders()`, `cleanDatabaseStorage()`, `ensureRedlineDirectories()`,
  `composerJsonFixture()` / `cleanComposerJsonFixtures()` (throwaway host composer.json
  fixtures - the command tests must never write testbench's own composer.json).
- Each test gets a fresh in-memory SQLite database (Orchestra Testbench;
  `database.connections.redline` pre-set to `:memory:`).
- `tests/TestCase.php` boots with `APP_ENV=staging` + `app.debug=true`, re-wires the
  pluggable providers, routes and views (the real service provider skips that wiring
  under `runningInConsole()`), appends `ShowRedlineWidget` to the `web` group, and
  disables Laravel's built-in CSRF for tests.
- `tests/ArchTest.php` (pest-plugin-arch) - global ban on `dd`, `dump`, `ray`.
- JWT signing key in tests is `config('app.key')` (decoded when `base64:`-prefixed).
- `testbench.yaml` is present and **committed** (was gitignored in the legacy monorepo).

## Conventions

- PHP: PSR-4 `Zipavlin\Redline\` → `src/`; tests `Zipavlin\Redline\Tests\` → `tests/`.
- Linting: Pint (Laravel defaults; no custom `pint.json`).
- EditorConfig: LF line endings, 4-space indent, UTF-8.
- Migrations numbered (`0001_`–`0009_`) in `database/redline/`, not timestamped; created
  by `redline:install` for file databases, auto-run for in-memory test DBs. `ensureSchema()`
  never checks for per-migration drift - never add a per-migration guard there.
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
