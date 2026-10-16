# v0.3.0 ()

## Added

- **MCP server for AI coding agents** - let Claude Code, OpenCode, Codex, PI,
  Cursor, VS Code or Gemini CLI read and triage your reports. Turn it on with
  `php artisan redline:enable mcp`, run `php artisan redline:migrate`, and the
  endpoint is served at `{redline.path}/api/mcp`. Agents get eight tools:
  `report_list`, `report_get`, `screenshot_get`, `comment_list` and `user_list`
  to read, and `report_create`, `comment_create` and `report_update_status` to
  write. Writes follow the same rules as the dashboard (sanitized HTML, role
  permissions, status transitions, new-report mail). `report_get` leaves a new
  report untouched unless you pass `mark_noticed`. Agents can look users up with
  `user_list`, but there is no tool to assign members yet. Report content is
  user-written, so the server tells agents to treat it as data, not
  instructions. Contract: `redline-app/contracts/mcp.md`.
- **`redline:mcp` token wizard** - run `php artisan redline:mcp` to pick a user,
  see their active tokens, create a new one or revoke an old one, then choose
  your coding agent and get a copy-paste setup for it. The token is shown once;
  copy it right away. For scripts: `--list`, `--revoke=`, `--revoke-all`,
  `--user=` and `--name=`. Tokens start with `rlm_`, only work as a
  `Authorization: Bearer` header, never expire, and stop working when the
  user's password changes. Logging out does not revoke them. Revoke them from
  the command line.
- **Agent-friendly report creation** - `report_create` explains how to set
  `element` (a precise CSS selector, checked against the live page when the
  agent has a browser, at most 512 characters) and the viewport-relative `pin`.
  The report's `location` is worked out from its `url`, so agents only send the
  URL.
- **`redline:enable`** - list the features and switch them on or off from the
  command line.

## Changed

- **Laravel 11 is no longer supported** - Redline now requires Laravel 12.41.1
  or newer (or 13). The MCP server's dependencies need it. Hosts on Laravel 11
  must upgrade Laravel before updating Redline.
- **Features are now opt-in** - nothing is on until you list it in
  `REDLINE_FEATURES` (for example `REDLINE_FEATURES=screenshots,comments,mcp`),
  replacing `REDLINE_DISABLED`. The installer asks which features you want and
  `redline:update` shows what is enabled. Existing installs need to set
  `REDLINE_FEATURES` once, otherwise every feature stays off. A misspelled name
  stops the app with an error instead of being ignored; clear the cached config
  if you hit it. `GET /bootstrap` only advertises enabled features.
- **Report `element` limit over MCP** - agents may send at most 512 characters;
  the dashboard and widget are unaffected.

## Fixed

- **Redline answers 404 when the guard is inactive, even with a route cache** -
  the guard is now checked on every request, for the API, the dashboard shell
  and the MCP route. Running `route:cache` or `optimize` in one environment can
  no longer expose Redline on a host where the guard says it should be off.
  With the guard inactive Redline leaves no trace.
- **Redline routes survive `route:cache`** - they were previously missing from a
  cached route list.
- **Forbidden report submissions return `403`** - `POST /report` from a user who
  may not create reports used to fail with a server error.

# v0.2.0 (2026-10-14)

## Added
- **Report members** - collaborate on a report by assigning members:
  `POST /report/{report}/member` and `DELETE /report/{report}/member` (both take
  a `user_ids` array; developer+ or the report's author; any member can remove
  themselves regardless of role). Members are surfaced through the grammar's
  `members` read, and the status response includes the current list. The author
  is never a member: posting the author's id is a no-op (not a 422), member
  reads exclude the author, and migration `0008` removes historical author
  rows. Claiming a report (`noticed → progress`) auto-assigns the acting user
  when they are eligible (`approved`, non-guest). `GET /user` is now open to
  any authenticated user so the member picker can list people.
- **Priorities** - reports carry a priority (`low|normal|high|critical`,
  default `normal`), part of every report row and settable via
  `PATCH /report/{report}/priority` (developer+ or the owner).
- **Due dates** - reports carry a `due_at` timestamp, sortable and filterable
  through the grammar. Stored as a naive UTC wall clock, normalized on write
  and read, serialized as RFC3339 `Z`.
- **Laravel host-session auth** - `LaravelAuthProvider` resolves a Redline
  identity from the host application's logged-in `User`, and
  `LaravelGuardProvider` injects the widget and dashboard for
  Laravel-authenticated users. No separate Redline login needed.
- **`redline:update`** (alias `redline:upgrade`) - idempotent upgrade for
  existing installs: backs up the SQLite database, runs pending package
  migrations, re-publishes assets, offers to re-publish customized config/mail
  views (never overwritten under `--force`), and clears config/view/route
  caches. Flags: `--force`/`-F`, `--dry-run`, `--no-backup`.
- **`redline:user` can promote and edit** - beyond creating users, the command
  can now promote an existing user to another role and edit name/email.
- **Self-updating installs** - a Composer `post-package-update` hook applies
  new migrations (with a pre-migration backup) and re-publishes assets when
  the host app updates the package; `StorageProvider::ensureSchema()` no
  longer re-runs per-migration guards on every boot.

## Changed

- **Wire contract v2** - `GET /{path}/api/bootstrap` reports `"version": 2`.
  The report `has_media` filter is removed; use `screenshot:exists` /
  `video:exists` existence filters instead.
- **Email PII is developer+ only** - on `GET /user`, `include[]=email` and
  `filter[email]=…` now require a developer or admin; other roles get `422`
  (an explicit denial, not a silently missing key). The `search` OR-box
  matches emails only for developers/admins; non-developers search names only.
  `filter[author.email:like]` on `/report` remains rejected.
- **Case-insensitive emails** - user emails are normalized (trimmed,
  lowercased) on write and matched case-insensitively on lookup.
- **Leaner report payloads** - `user_agent` is no longer serialized with
  reports.
- **Single storage backend** - the Orbit/FileStorageProvider backend is
  removed; storage is one concrete `StorageProvider` on the dedicated SQLite
  connection, and the Base/Database model layers are collapsed into single
  concrete models.

## Fixed

- **Pending accounts can no longer bypass auth** - the API rejects
  authentication for pending-approval users with `401`, the pending transition
  bumps `token_version` so tokens issued before approval stop working, and
  login returns the canonical `202` response without a token.
- **No JWT in media URLs** - media is no longer served through URLs embedding
  a JWT; it is served under the established cookie/CSRF authentication.
- **Origin gate on every login mode** - the request-origin check now applies
  to all login modes, not only guest registration.
- **Mentions survive sanitization** - TipTap mention spans are preserved by
  the HTML sanitizer.

# v0.1.0 (2026-08-11)

Initial release.
