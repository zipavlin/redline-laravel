# Commands

Redline ships exactly three artisan commands. They are registered **unconditionally** - in every
environment and regardless of guard, auth, or storage configuration - so setup and teardown always
work, even before the package is "active" on a given host:

| Command | Purpose |
|---|---|
| `php artisan redline:install` | Interactive first-time setup |
| `php artisan redline:user` | Create a user directly, any role |
| `php artisan redline:remove` | Permanently remove Redline, its data, and (optionally) the package |

Each invocation reads the current `.env` / published config at runtime, so whatever
`REDLINE_*` values are set when you run the command are the ones it acts on.

## redline:install

`php artisan redline:install` walks you through the whole setup interactively: storage backend,
auth provider, guard provider, disabled features, email, publishing, database schema, and the
first admin user. Every choice is written to `.env` as a `REDLINE_*` (or `MAIL_*`) variable, so a
rerun is safe - existing values are updated in place, and the command can be re-run after switching
providers.

### Step 1 - Storage provider

```
Storage provider
Controls how data is saved.
Which storage backend would you like to use? [Database ...]
```

Writes `REDLINE_STORAGE_PROVIDER` with the selected provider's FQCN.

- **Database** (default) - `Zipavlin\Redline\Providers\Storage\DatabaseStorageProvider`. The
  command creates `storage/app/redline/database/`, registers an SQLite connection named `redline`
  pointing at `storage/app/redline/database/redline.db`, and runs the numbered migrations
  (`0001`–`0005`) from `vendor/zipavlin/redline/database/redline` on it:

  ```
  Running database migrations …
  ✓ Redline database tables created.
  ```

- **File** - `Zipavlin\Redline\Providers\Storage\FileStorageProvider`. If
  `ryangjchandler/orbit` is not installed you are offered to install it right away
  (`composer require ryangjchandler/orbit`); if that is skipped, the command tells you to run
  `redline:install` again afterwards.

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

### Step 4 - Disabled features

```
Features
All features are enabled by default. Select any you want to disable.
Which features should be disabled? (separate multiple with commas, or press Enter for none)
```

The choices are exactly `screenshots`, `videos`, `files`, `comments`. An empty answer disables
nothing. Only when you select at least one is `REDLINE_DISABLED` written, as a comma-separated
list.

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

### Step 7 - First admin user

```
First admin user
Would you like to create the first admin user? [yes]
```

Runs `redline:user` in-process. (If you chose the File storage backend but orbit is still not
installed, this is skipped with instructions to run `composer require ryangjchandler/orbit &&
php artisan redline:user` manually.)

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
| `REDLINE_STORAGE_PROVIDER` | always | Storage provider FQCN |
| `REDLINE_AUTH_PROVIDER` | always | Auth provider FQCN |
| `REDLINE_GUARD_PROVIDER` | always | Guard provider FQCN |
| `REDLINE_AUTH_PIN_REPORTER` | Pin auth, PIN given | Reporter registration PIN |
| `REDLINE_AUTH_PIN_DEVELOPER` | Pin auth, PIN given | Developer registration PIN |
| `REDLINE_AUTH_DOMAINS` | Domain auth | Comma-separated allowed email domains |
| `REDLINE_GUARD_ALLOWED_IPS` | IP guard, IPs given | Comma-separated allowed IPs |
| `REDLINE_DISABLED` | ≥ 1 feature selected | Comma-separated disabled features |
| `MAIL_MAILER`, `MAIL_HOST`, `MAIL_PORT`, `MAIL_USERNAME`, `MAIL_PASSWORD`, `MAIL_FROM_ADDRESS`, `MAIL_FROM_NAME` | Resend or SMTP chosen | Mail transport |
| `RESEND_API_KEY` | Resend chosen, key given | Resend API key |

It also publishes the selected config/assets and, for the Database backend, creates the Redline
schema on the `redline` SQLite connection.

## redline:user

`php artisan redline:user` creates a Redline user **directly in storage**, bypassing all
auth-provider registration policies - it works even under
`Zipavlin\Redline\Providers\Auth\ClosedAuthProvider`, and it can create an admin regardless of
`REDLINE_AUTH_ALLOW_SELF_ADMIN`. It is the standard way to seed the first admin user.

```
  ● Redline - create user

Name: Jane Doe
Email: jane@example.com
Password (min 6 characters): ********
Role: [reporter | developer | admin]
```

Rules enforced by the command:

- Name is required.
- Email must be a valid address and must not already exist.
- Password is prompted as hidden input and must be at least **6 characters** - note this is
  *looser* than the 8-character minimum enforced by the register/reset flows in the API.
- Role defaults to **admin**; the other options are `reporter` and `developer`.

The storage provider is booted first; if it cannot boot (e.g. the SQLite schema is missing for
`DatabaseStorageProvider`), the command fails with the provider's message - run
`redline:install` first.

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
   `storage/app`. For the cloud storage provider there is nothing local to delete - reports live
   on the remote Redline API and are deleted there.
2. **Assets** - the published `public/vendor/redline` directory.
3. **Config** - `config/redline.php` and any published mail views under
   `resources/views/vendor/redline`.
4. **`.env` entries** - every line whose key starts with `REDLINE_` is stripped (other lines,
   including any `MAIL_*` keys the installer wrote, are left byte-for-byte intact).
5. **Orbit cache** - only when the File provider with orbit is active; asks for confirmation (or
   removes it automatically under `--force`).

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
| `redline:install` | Interactive setup: storage, auth, guard, features, email; writes `.env`, publishes config/assets, creates DB schema, first admin | - |
| `redline:user` | Create a user directly in storage with any role; bypasses auth-provider policies (password min 6) | - |
| `redline:remove` | Wipe all Redline data, published assets/config, and `REDLINE_*` `.env` entries; scan for manual references; optionally `composer remove zipavlin/redline` | `-F`, `--force` - skip all confirmations (including package removal) |

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
