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
