# Commands

Redline ships exactly six artisan commands. They are registered **unconditionally** - in every
environment and regardless of guard, auth, or storage configuration - so setup and teardown always
work, even before the package is "active" on a given host:

| Command | Purpose |
|---|---|
| `php artisan redline:install` | Interactive first-time setup |
| `php artisan redline:update` | Upgrade an existing install to the package version |
| `php artisan redline:enable` | List / enable / disable features (writes `REDLINE_FEATURES`) |
| `php artisan redline:user` | Create a user directly, any role |
| `php artisan redline:mcp` | Interactive MCP token wizard / create / list / revoke MCP API tokens |
| `php artisan redline:remove` | Permanently remove Redline, its data, and (optionally) the package |

Each invocation reads the current `.env` / published config at runtime, so whatever
`REDLINE_*` values are set when you run the command are the ones it acts on.

## redline:install

`php artisan redline:install` walks you through the whole setup interactively: auth
provider, guard provider, disabled features, email, publishing, database schema, and the
first admin user. Every choice is written to `.env` as a `REDLINE_*` (or `MAIL_*`) variable, so a
rerun is safe - existing values are updated in place, and the command can be re-run after switching
providers.

### Step 1 - Storage

```
Storage provider
Controls how data is saved.
Running database migrations …
✓ Redline database tables created.
```

There is one local backend, so there is no choice and nothing to write to `.env`. The command creates `storage/app/redline/database/`, registers an SQLite
connection named `redline` pointing at `storage/app/redline/database/redline.db`, and
runs the numbered migrations (`0001`–`0009`) from
`vendor/zipavlin/redline/database/redline` on it.

### Step 2 - Auth provider

```
Auth provider
Controls how users register and which roles they can claim.
Which auth provider would you like to use? [Open ...]
```

Writes `REDLINE_AUTH_PROVIDER` with the selected provider's FQCN (Open, Pin, Domain, Approval,
Closed, or Cloud - see the Authentication chapter). Two providers trigger extra prompts:

- **Pin** - you are asked for a Reporter PIN and a Developer PIN (each may be left blank to skip,
  but the two may not be identical). Non-empty values are written to
  `REDLINE_AUTH_PIN_REPORTER` and `REDLINE_AUTH_PIN_DEVELOPER`.
- **Domain** - you are asked for allowed email domains, comma-separated (e.g.
  `example.com,acme.org`), written to `REDLINE_AUTH_DOMAINS`.

### Step 3 - Guard provider

```
Guard provider
Controls when Redline (widget and dashboard) is loaded.
Which guard provider would you like to use? [Environment ...]
```

Writes `REDLINE_GUARD_PROVIDER` with the selected provider's FQCN (Environment, Debug, or IP -
see the Guards chapter). The **IP** guard additionally asks for allowed IPs, comma-separated,
written to `REDLINE_GUARD_ALLOWED_IPS`.

### Step 4 - Features (opt-in)

```
Features
Nothing is enabled by default. Select any you want to turn on.
Which features should you enable? (all off by default - separate multiple with commas, or press Enter for none)
```

The choices are exactly `screenshots`, `videos`, `files`, `comments`, `mcp`. An empty answer enables
nothing. `REDLINE_FEATURES` is always written (even as an empty value) as a comma-separated
list of the selected features.

When the selection includes `mcp`, the command also points at the two steps an agent setup
needs beyond the flag:

```
  • MCP server on - run php artisan redline:mcp: it issues a token and prints your agent's setup command
    and point your MCP client at: https://staging.example.com/redline/api/mcp
```

(the endpoint is derived from `APP_URL` and `REDLINE_PATH`, never hardcoded). The full MCP
setup - tokens, per-client configuration, revocation semantics - lives in "MCP server" in
the README.

### Step 5 - Email setup

This step runs only when no usable mailer is configured yet (the default mailer is not
`log`/`array`/`null` and `REDLINE_MAIL` is not `false`). If your chosen auth provider verifies new users by email, the command warns
that verification and password-reset mail will not send until a mailer exists; otherwise it notes
that Redline degrades gracefully - those emails are simply skipped.

```
How would you like to set up email?
 [0] Resend (API) - requires verifying a sending domain (DNS)
 [1] SMTP without DNS (recommended for staging - e.g. Mailjet)
 [2] Skip - I'll configure a mailer later
```

- **Resend** - installs `resend/resend-php`, writes `MAIL_MAILER=resend`, asks for the API key
  (written to `RESEND_API_KEY`), and reminds you that `config/services.php` needs a
  `'resend' => ['key' => env('RESEND_API_KEY')]` entry (Laravel 11+ ships it). Resend requires a
  sending domain verified via DNS in the Resend dashboard before mail reaches real recipients.
- **SMTP** - prompts for host (default `in-v3.mailjet.com`), port (default `587`), username,
  password (hidden input), from address, and from name (default `Redline`). Writes
  `MAIL_MAILER=smtp`, `MAIL_HOST`, `MAIL_PORT`, `MAIL_USERNAME`, `MAIL_PASSWORD`,
  `MAIL_FROM_ADDRESS`, `MAIL_FROM_NAME`, plus guidance on Mailjet sender verification and on
  setting a `'timeout' => 10` in `config/mail.php` if a send ever hangs the request.
- **Skip** - writes nothing; the command suggests setting `REDLINE_MAIL=false` later to silence
  the warning.

### Step 6 - Config & assets

```
Publish Redline config? This is not needed - you can set Redline in .env [no]
Publish Redline assets to public/vendor/redline/? [yes]
```

- Config publication (`vendor:publish --tag=redline-config` → `config/redline.php`) is **off by
  default** - everything is configurable via `.env`. If you accept and the file already exists,
  you are asked whether to overwrite it.
- Assets (`vendor:publish --tag=redline-assets` → `public/vendor/redline/`) are **on by default**
  - the widget and dashboard scripts must be publicly reachable.

### Step 7 - Composer updates

No prompt. The installer writes the `post-package-update` hook into the host's
`composer.json`:

```
Composer updates
✓ Added the composer post-package-update hook to composer.json (runs redline:update automatically).
```

An already-present hook prints `• Composer post-package-update hook already present in
composer.json.` and changes nothing. A missing or unreadable `composer.json` only warns (the
install still succeeds) - `redline:update` offers to add the hook later. See
[Installation](02-installation.md#automatic-updates-via-composer).

### Step 8 - First admin user

```
First admin user
Would you like to create the first admin user? [yes]
```

Runs `redline:user` in-process.

The command finishes with next steps:

```
✓ Redline installed successfully!

Next steps:
1. Set APP_ENV=staging and APP_DEBUG=true on your staging server.
2. Deploy - the widget will appear automatically.
3. Visit /redline/dashboard to review reports.
```

### What redline:install writes

| Variable | Written when | Purpose |
|---|---|---|
| `REDLINE_AUTH_PROVIDER` | always | Auth provider FQCN |
| `REDLINE_GUARD_PROVIDER` | always | Guard provider FQCN |
| `REDLINE_AUTH_PIN_REPORTER` | Pin auth, PIN given | Reporter registration PIN |
| `REDLINE_AUTH_PIN_DEVELOPER` | Pin auth, PIN given | Developer registration PIN |
| `REDLINE_AUTH_DOMAINS` | Domain auth | Comma-separated allowed email domains |
| `REDLINE_GUARD_ALLOWED_IPS` | IP guard, IPs given | Comma-separated allowed IPs |
| `REDLINE_FEATURES` | always | Comma-separated enabled features (empty = all off) |
| `MAIL_MAILER`, `MAIL_HOST`, `MAIL_PORT`, `MAIL_USERNAME`, `MAIL_PASSWORD`, `MAIL_FROM_ADDRESS`, `MAIL_FROM_NAME` | Resend or SMTP chosen | Mail transport |
| `RESEND_API_KEY` | Resend chosen, key given | Resend API key |

It also publishes the selected config/assets, adds the composer `post-package-update` hook
to `composer.json`, and creates the Redline SQLite schema on the `redline` connection.

## redline:enable

`php artisan redline:enable` lists or toggles the feature set. Features are **additive**:
nothing is on until `REDLINE_FEATURES` names it.

```bash
php artisan redline:enable                  # list every feature + state
php artisan redline:enable mcp comments     # enable (comma/space/repeated arguments)
php artisan redline:enable --disable=mcp    # disable
```

The listing reads:

```
  Redline features - driven by REDLINE_FEATURES; every feature is off until it is listed:
  screenshots disabled
  videos     disabled
  files      disabled
  comments   disabled
  mcp        disabled

  Toggle with `php artisan redline:enable <names>` or `--disable=<names>`.
```

Toggles write the resulting set to `REDLINE_FEATURES` in fixed enum order and echo it
(`✓ REDLINE_FEATURES=comments`, or `✓ REDLINE_FEATURES= (no features enabled)` for an empty
set - the key is kept so the all-off state is explicit). An unknown feature name is an error
(exit 1) and writes nothing:

```
  Unknown Redline feature(s): vidoes. Valid features: screenshots, videos, files, comments, mcp.
```

## redline:mcp

`php artisan redline:mcp` manages the long-lived **MCP API tokens** (`rlm_` + 40 hex
chars, sha-256-hashed in `redline_tokens`). Bare, it runs an **interactive
wizard**: pick an approved non-guest user (a typed filter/exact-email fallback
appears when the pool is over ~25), see the roster of that user's active tokens
(`Add new token` or `Revoke a token`), then pick the agent preset - the
command prints a ready-to-paste setup block for that agent with the real
endpoint and token inline. The default token name is the selected agent's
slug; `Other` asks for a typed name; an active token of the same name asks
whether to **replace** (revoke, then mint) or **add**.

The flags keep the classic non-interactive behaviour:

```bash
php artisan redline:mcp                       # interactive wizard (default)
php artisan redline:mcp --user=me@example.com --name="Claude"
php artisan redline:mcp --list --user=me@example.com       # name, hint, created, last used
php artisan redline:mcp --revoke={id}                      # soft-revoke one token
php artisan redline:mcp --revoke-all --user=me@example.com # revoke every token of a user
```

With `--name=` matching a known agent slug the create prints that agent's
instruction block too. The roster (`--list`, also shown in the wizard) renders
`name · ···<4-char hint> · created Y-m-d · last used Y-m-d` - the name column
shows the humanized agent label when the name is a known agent slug. The hint
(the last 4 characters of the plaintext) is an identification device only,
never a credential.

The plaintext is printed once, preceded by this warning (quoted verbatim, do not reword):

> This token acts with the user's full permissions - treat it as a password;
> it is NOT invalidated by logout; it IS invalidated by that user's password change.

- Guest and pending users are refused (no token is ever issued to them).
- A password change by the owner invalidates every outstanding token (per-user
  `password_epoch` snapshotted at creation); logout does not.
- `redline:remove` destroys the token table with the rest of the host database.
- The same bearer works on the whole REST surface, including screenshot/video binary
  URLs; it is never accepted from a cookie or a query string.
- Every client preset sends the `Authorization` header per request on the
  streamable-HTTP transport, which is why Redline never accepts an `rlm_`
  token from a cookie - none of the listed clients ever send `rlm_` via
  cookies.

See "MCP server" in the README for per-client configuration (Claude Code,
OpenCode, Codex, PI, Cursor, VS Code and Gemini CLI).

## redline:update

`php artisan redline:update` (alias `php artisan redline:upgrade`) upgrades an
already-installed Redline to the installed package version - run it after a
`composer update` brings in a new Redline release. It is idempotent and safe
to re-run.

```bash
php artisan redline:update              # interactive, with confirmations
php artisan redline:update --force      # non-interactive, for deploy scripts (-F)
php artisan redline:update --dry-run    # reports pending migrations and planned steps, mutates nothing
php artisan redline:update --no-backup  # skip the pre-migration SQLite backup
```

### Steps

1. **Preflight** - verifies Redline is installed (the `redline` SQLite
   connection exists and the `redline_reports` table is present). If not, it
   errors and tells you to run `redline:install` first. An update never
   creates the database or its directories - that is `redline:install`'s job.
2. **Pending migrations** - migrations are **not** published to the host; they
   run from the package path (`database/redline`), and Laravel's `migrations`
   table on the `redline` connection tracks which ran - so "pending" means not
   yet applied. Prints `Database is already up to date.` when there is nothing
   to do. (If the migration state cannot be read, it says so and attempts the
   migrate anyway.)
3. **SQLite backup** - copies the database file to
   `{db}.{YYYYmmdd_HHMMSS}.bak` before migrating. Skipped for `:memory:`
   databases and under `--no-backup` (which prints a skip note). If the backup
   fails, the command aborts before migrating.
4. **Migrate** - runs the pending migrations (`migrate --path=<package
   database/redline> --realpath --database=redline --force`).
5. **Assets** - re-publishes to `public/vendor/redline` with `--force`. Unlike
   `redline:install`, which publishes without `--force`, a plain re-publish
   would keep serving stale JS/CSS.
6. **Published config** - only when `config/redline.php` exists: warns it may
   miss new options and offers to re-publish (default No). Under `--force` it
   is left untouched with a manual-merge note - never clobbered.
7. **Published mail views** - only when `resources/views/vendor/redline/mail`
   exists: offers to re-publish (default No; never clobbered under `--force`).
8. **Clear caches** - runs `config:clear`, `view:clear`, `route:clear`, since
   stale caches can pin the previous version after a composer update.
9. **Composer hook** - when `composer.json` already carries the Redline
   `post-package-update` entry it says so and skips; under `--force` it prints
   `--force: skipped the composer post-package-update hook.` and writes nothing;
   otherwise it asks
   `Set up Redline to update automatically on package updates? (composer
   post-package-update hook)` (default **Yes**) and appends the entry, so later
   `composer update` runs are automatic. An unwritable or invalid `composer.json`
   only warns - the upgrade still succeeds.
10. **Summary** - version, migration count, and asset re-publish, plus a
    `Left untouched: …` line naming any published config/mail views that were
    left in place (omitted when there were none, or when they were re-published).

With `--dry-run` the command stops after step 2 and prints the planned steps
instead (pending-migration count, asset target, whether published config or
mail views would trigger an offer, whether the composer hook is missing) - it
mutates nothing.

## redline:user

`php artisan redline:user` manages Redline users **directly in storage**, bypassing all
auth-provider registration policies - it works even under
`Zipavlin\Redline\Providers\Auth\ClosedAuthProvider`, and it can create an admin regardless of
`REDLINE_AUTH_ALLOW_SELF_ADMIN`. It is the standard way to seed the first admin user.
`--promote` and `--edit` are mutually exclusive - passing both errors out (exit 1).

### Create (no flag)

```
  ● Redline - user management

Name: Jane Doe
Email: jane@example.com
Password (min 6 characters): ********
Role: [reporter | developer | admin]
```

- Name is required (trimmed).
- Email must be a valid address and must not already exist - the duplicate check is
  case-insensitive.
- Password is prompted as hidden input and must be at least **6 characters** - note this is
  *looser* than the 8-character minimum enforced by the register/reset flows in the API.
- Role is `reporter` | `developer` | `admin`, defaulting to **admin**.

Under `Zipavlin\Redline\Providers\Auth\LaravelAuthProvider` (or a subclass) creation is
refused (exit 1): users are provisioned automatically on first host-application login, so
manage them in the host app and use `--edit` / `--promote` for existing Redline users:

```
Users are provisioned automatically on first host-application login under LaravelAuthProvider.
  Manage users in the host application instead of creating them here.
  Use --edit or --promote to adjust an existing Redline user.
```

### Promote (`--promote`)

```bash
php artisan redline:user --promote
```

Lists every non-admin user (the shared guest identity is never listed) as a multi-select;
each selected user is promoted to **admin**. When there is nothing promotable the command
prints a message and exits 0, and an empty selection prints
`No users selected; nothing promoted.` (exit 0):

```
  ✓ Promoted Jane Doe (jane@example.com) to admin.
```

### Edit (`--edit`)

```bash
php artisan redline:user --edit
```

Select one user (guest excluded), then re-answer each attribute - blank keeps the current
value, except Role, which preselects the current one:

- Name - blank keeps the current value; an empty name errors.
- Email - blank keeps the current value; otherwise it must be valid and case-insensitively
  unique (excluding the user being edited).
- Password - blank keeps the current value; when given, min 6 characters, re-hashed on save,
  and bumps `token_version`, invalidating all outstanding JWTs.
- Role - `reporter` | `developer` | `admin`, preselected to the current role of the user.

The command prints each changed attribute, or `No changes made.` when nothing changed:

```
  ✓ Updated name.
  ✓ Password updated.
  ● Existing sessions invalidated (all tokens revoked).
```

Under `LaravelAuthProvider`, changing the email of a user additionally warns that the next host
login for the new address provisions a NEW reporter - email is the host-session join key.

The storage provider is booted first in every mode; if it cannot boot (e.g. the SQLite schema
is missing), the command fails with the boot error message - run `redline:install` first.

## redline:remove --force

```bash
php artisan redline:remove              # interactive, with confirmations
php artisan redline:remove --force      # same, but skips every confirmation (-F)
```

> **Warning** - `redline:remove` permanently deletes **all** Redline data (reports, users,
> comments, files) from the host app, plus published assets, published config, and `.env` entries.
> There is no undo. With `--force` it also removes the package itself without asking.

Without `--force`, the command requires you to type a confirmation token derived from `APP_URL`:
the first label of the host, lowercased. For `https://staging.example.com` the token is
`staging`; if `APP_URL` is unset or unparseable it falls back to `redline`.

```
  ⚠ IMPORTANT This will remove ALL Redline data.

Please write 'staging' to continue:
```

### What gets removed

1. **Storage** - the whole `storage/app/redline` directory (from
   `REDLINE_STORAGE_PATH_BASE`). It refuses to delete anything that does not resolve inside
   `storage/app`.
2. **Assets** - the published `public/vendor/redline` directory.
3. **Config** - `config/redline.php` and any published mail views under
   `resources/views/vendor/redline`.
4. **Composer hook** - drops only the Redline `post-package-update` entry from
   `composer.json`, leaving any other listener and every other key intact. No hook (or an
   unreadable `composer.json`) prints a note and continues.
5. **`.env` entries** - every line whose key starts with `REDLINE_` is stripped (other lines,
   including any `MAIL_*` keys the installer wrote, are left byte-for-byte intact).

### Manual-cleanup scan

The command then scans your project's `*.php` files (excluding `vendor/`, `node_modules/`,
`storage/`, `bootstrap/cache/`, dotfiles, and VCS directories) for lines containing
`ShowRedlineWidget`, `Zipavlin\Redline`, or `RedlineServiceProvider`, and prints each
`file:line` so you can remove manual references (middleware, service-provider registration,
imports) by hand:

```
  ⚠ app/Providers/AppServiceProvider.php:42 - contains RedlineServiceProvider, remove manually
```

### Removing the package

Finally it asks:

```
Do you want to remove the Redline package as well? [no]
```

If you accept, it runs `composer remove zipavlin/redline` (5-minute timeout). If that fails it
prints the manual command plus a reminder to delete leftover Redline files and `REDLINE_*` entries
by hand. With `--force`, this prompt is skipped and the package is removed automatically.

## Command reference

| Command | Purpose | Flags |
|---|---|---|
| `redline:install` | Interactive setup: storage, auth, guard, features, email; writes `.env`, publishes config/assets, adds the composer `post-package-update` hook, creates DB schema, first admin; prints the MCP token/endpoint hints when `mcp` was selected | - |
| `redline:update` (alias `redline:upgrade`) | Upgrade an existing install: preflight check, pending migrations (+ SQLite backup), assets re-published with `--force`, optional config/mail re-publish, composer hook offer, cache clears; prints the current feature state | `-F`, `--force` - non-interactive; published config/mail views and the composer hook left untouched; `--dry-run` - report only; `--no-backup` - skip backup |
| `redline:enable` | List every feature + state; enable/disable by name in fixed enum order; writes `REDLINE_FEATURES` (key kept even when all off); unknown name exits 1 writing nothing | `feature*` (positional, comma/space separated); `-D`, `--disable=*` |
| `redline:user` | Create/edit/promote a user directly in storage; bypasses auth-provider policies (password min 6) | `--promote`, `--edit` (mutually exclusive) |
| `redline:mcp` | Interactive MCP-token wizard plus create/list/revoke (`redline_tokens`); refuses pending/guest owners; plaintext shown once; roster carries a 4-char identification hint | bare = wizard, `--name=`, `--user=`, `--list`, `--revoke={id}`, `--revoke-all` (needs `--user=`) |
| `redline:remove` | Wipe all Redline data (including `redline_tokens`), published assets/config, the composer hook and `REDLINE_*` `.env` entries; scan for manual references; optionally `composer remove zipavlin/redline` | `-F`, `--force` - skip all confirmations (including package removal) |

## Related

- [Introduction](01-introduction.md)
- [Installation](02-installation.md)
- [Configuration](03-configuration.md)
- [Storage](04-storage.md)
- [Authentication](05-authentication.md)
- [Guards](06-guards.md)
- [API / Resources](07-resources.md)
- [Emails](09-emails.md)
- [Widget & Dashboard](11-widget-dashboard.md)
