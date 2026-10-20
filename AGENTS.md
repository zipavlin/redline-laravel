# AGENTS.md - Redline (Laravel backend)

Composer package **`zipavlin/redline`** (public repo `github.com/zipavlin/redline-laravel`) -
the Laravel backend for Redline: a floating bug-reporting widget + review dashboard for
staging environments. Not a standalone app; installs into a host Laravel app. Frontend
source lives in the sibling **redline-app** repo (see "Related repositories").

## Activation model (do not oversimplify this)

The package is **not** "completely inert outside staging". Only parts of it are gated:

- **Always registered**, regardless of environment: `vendor:publish` tags
  (`redline-assets` → `public/vendor/redline`, `redline-config` → `config/redline.php`,
  `redline-mail-views` → `resources/views/vendor/redline/mail`) and the 6 artisan commands
  (`redline:install`, `redline:update`, `redline:user`, `redline:mcp`, `redline:remove`,
  `redline:enable`).
- **Loaded whenever a local storage provider is active**: the dashboard shell
  (`routes/web.php` catch-all serving the SPA at `/{redline.path}`, default `/redline`).
- **Route caching IS supported**: the package route files AND the MCP route register in
  the provider's `booted()` callback too (console included), so `php artisan route:cache`
  compiles a cache that holds them - they no longer silently vanish. Console-time route
  loading is unconditional (no guard decision at compile time - one cannot be made for
  request-state guards, and a cache is environment-portable). See
  `RedlineServiceProvider::registerRoutesAcrossCaching()`.
- **Gated by the guard**: the API routes (`routes/api.php`) and widget injection
  (`ShowRedlineWidget` middleware, appended to the `web` group). The guard is
  enforced PER REQUEST on the api + web surface - `RedlineActive` middleware
  (default 404, `:403` on the web group) consults the guard's
  `shouldServeRoutes(request)` NOT `isActive()`: session guards cannot answer
  before the session stack runs (the api group nests that stack INSIDE
  `RedlineActive`), and asking `isActive()` there would deny every
  request, the `LaravelAuthProvider` login exchange included. The guard
  exposes three distinct answers: `isActive()` = is Redline active for this
  request; `shouldServeRoutes()` = the per-request middleware predicate
  (default `isActive()`, NO `REDLINE_GUARD_EXCLUDE` coupling - exclusion
  never disables the surface);
  `shouldLoadMiddleware()` = the boot-time wiring decision consulted when
  appending widget injection, which DOES fold in `REDLINE_GUARD_EXCLUDE`
  via `shouldShowWidget()` (correct there: at boot we decide whether to
  inject the widget, not whether to serve routes). Because the default
  reduces `shouldServeRoutes()` to `isActive()`, a route cache built in one
  environment still denies the public surface on a host where the guard is
  inactive. The MCP route is
  wrapped the same way, from a route GROUP that leads every shape laravel/mcp
  registers for the URI: `RedlineActive` first, then `RedlineMcpEnabled`, ahead
  of laravel/mcp's own header middleware and of the rate limiters and
  `RedlineApiAuth`. A guard-inactive host therefore answers 404 on the POST
  server route AND on the vendor's unnamed GET/DELETE probes - which would
  otherwise be a 400 on a malformed-header POST and a 405 on a method probe -
  before any limiter side effect or authentication attempt, with or without a
  route cache; the `mcp`-feature 404 rides the same group for all three shapes. Default `EnvironmentGuardProvider` is
  active when `APP_ENV=staging` (in the current code `APP_DEBUG` does **not**
  gate it - see `tests/Guard/EnvironmentGuardProviderTest.php`).
  `DebugGuardProvider` gates on `APP_DEBUG=true` instead. `LaravelGuardProvider`
  is active when the host application user is logged in - it overrides
  `shouldServeRoutes()` AND `shouldLoadMiddleware()` to always serve/wire up,
  because the session is not available before the session stack;
  per-request enforcement happens in `isActive()` (widget and
  the dashboard shell gate in `routes/web.php`, both behind the session stack).
  `REDLINE_GUARD_EXCLUDE` skips widget injection on matching paths (config default
  `null` - no exclusion; patterns support exact, subpath, `/*` and glob forms).

## Commands

### PHP (Pest)
```
composer test                    # full Pest suite (baseline: 698 passed, 0 skipped; see Test status)
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

`vendor/bin/pest` reports **698 passed, 0 skipped (2651 assertions)** (one test fewer
than the documented 699 baseline since the Pest 4.4.1 raise in `ea25a2a` dropped two
laravel/mcp header assertions and added one back). The package has a
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
list, sorted by `name` then `id` (the shared `Support\Members::forReport()` projection -
fetches the eager-loaded relation when present, the live query otherwise, and excludes
the author; also used by the read grammar, the status response and `fromUsers()`
underneath, so the surfaces cannot drift). The write-side helpers live in
`MemberActions` (author-id dropping, self-removal policy); eligibility (approved +
non-guest) is `User::isEligibleMember` / `scopeWhereEligibleMember()`.

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
  `redline-guest`, `redline-bootstrap` (`REDLINE_BOOTSTRAP_THROTTLE`, default 120/min/IP),
  `redline-mcp-ip` (`REDLINE_MCP_IP_THROTTLE`, 240/min/IP pre-auth flood control) and
  `redline-mcp` (`REDLINE_MCP_THROTTLE`, 120/min keyed `user id ?? IP`, post-auth)

### Features & mail

- `REDLINE_FEATURES` - comma-separated list (`screenshots, videos, files, comments, mcp`);
  additive opt-in, a feature is off unless listed; an unknown value is a config error thrown
  at boot. `mcp` gates the REDLI-51 MCP server: when enabled, `Mcp::web()` mounts
  `{redline.path}/api/mcp` (server shell `src/Mcp/RedlineServer.php`, eight tools:
  report_list/report_get/screenshot_get/comment_list/user_list + report_create/comment_create/
  report_update_status, wire names model-first snake_case, all through the shared
  `src/Actions/ReportActions.php` +
  `CommentActions.php` write path that the HTTP controllers also use - controllers call
  the actions; failures are package exceptions in `src/Exceptions/` (ForbiddenAction /
  FeatureDisabled / InvalidStatusTransition, HTTP 403 by construction; a wrong parent is a plain
  NotFoundHttpException) that the framework renders as 403/404 and tools map to actionable MCP errors)
  with group middleware `RedlineActive` → `RedlineMcpEnabled`, then
  `throttle:redline-mcp-ip` → `RedlineApiAuth` → `throttle:redline-mcp`, and no
  CSRF. Enforcement per-request: the `mcp`-feature check is GROUP middleware, so
  under a route cache compiled while the feature was on, turning it off still
  404s every shape laravel/mcp registers (the POST server route and the vendor's
  GET/DELETE probes), never a 400/405 or a stale server.
  `redline:install` prints a `redline:mcp` hint + the agent endpoint when the selected
  features include `mcp`. New `redline:enable` command lists/toggles features;
  `redline:update` prints the current feature state. Commands: `redline:install`,
  `redline:update`, `redline:enable`, `redline:user`, `redline:mcp`, `redline:remove`
  (six, not five).
- MCP API tokens: `rlm_` + 40 hex chars, sha-256-hashed in `redline_tokens`
  (migration `0010`, also adds `redline_users.password_epoch`). Issued only by
  `redline:mcp`; refused for pending/guest users; plaintext shown once.
  `password_epoch` bumps ONLY on a real password change (User's `updating` hook -
  logout and `bumpTokenVersion()` never touch it), and a token whose snapshot no longer
  matches is 401. Cookie-source `rlm_` values are explicitly rejected.
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

- `redline:install` - interactive setup (auth provider, email, enabled
  features (opt-in), writes `.env`, publishes config+assets, adds the composer
  `post-package-update` hook to the host composer.json; it also creates the
  SQLite schema).
- `redline:enable` - with no arguments lists every feature + state; `feature` args enable, `--disable=` disables; writes `REDLINE_FEATURES`; unknown name = error + exit 1.
- `redline:update` (alias `redline:upgrade`) - upgrade an installed Redline: run
  pending package migrations, re-publish assets, offer config/mail re-publish
  and the composer `post-package-update` hook (neither clobbered under `--force`);
  flags `--force`, `--dry-run`, `--no-backup`.
- `redline:user` - create/edit/promote users directly in storage (create blocked under `LaravelAuthProvider`); flags `--promote`, `--edit` (mutually exclusive).
- `redline:remove` - wipe all redline data (including the composer hook entry), removes redline from the project.
- `redline:mcp` - MCP API tokens in `redline_tokens`: bare = interactive wizard
  (pick an approved non-guest user; roster of active tokens → add/revoke; agent
  preset table `src/Commands/Concerns/AgentInstructions.php` → copy-paste setup
  for Claude Code / OpenCode / Codex / PI / Cursor (`.cursor/mcp.json`, no CLI
  add command; `${env:}` header reference plus a printed 401 caveat) / VS Code
  (`.vscode/mcp.json`, top-level `servers` key + an `inputs` promptString for
  the token; CLI alt `code --add-mcp`) / Gemini CLI (settings key `httpUrl`,
  not `url`; CLI alt `gemini mcp add --transport http --scope user`) - all
  seven shapes verified (2026-10), no `UNVERIFIED` slots remain; default name
  = the selected agent slug; same-name → replace/add),
  flags preserved: `--user=`, `--name=` (a known slug also prints that agent's
  block), `--revoke={id}`, `--revoke-all --user=`, `--list` (roster format
  `name · ···<4-char hint> · created Y-m-d · last used Y-m-d`, humanized agent
  label when the name is a known slug; the hint is identification only, never
  a credential). Refuses pending and guest users; plaintext printed once.

The host-side `post-package-update` listener lives in `src/ComposerHook.php` and the
composer.json writer shared by all commands in `src/Support/ComposerScripts.php`.
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
  pluggable providers, views and storage (the real service provider skips that wiring
  under `runningInConsole()`; it no longer re-loads the route files - the provider's
  booted() callback loads routes in the console, and TestCase must not duplicate it),
  appends `ShowRedlineWidget` to the `web` group, and
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
