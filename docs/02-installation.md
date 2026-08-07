# Installation

Redline is a Composer package that installs into an existing Laravel application - it is not a standalone app. It provides the backend (API, storage, auth) for the Redline bug-reporting widget and review dashboard; all frontend source lives in the separate [redline-app](https://github.com/zipavlin/redline-app) repository.

## Requirements

| Requirement | Version / note |
|---|---|
| PHP | `^8.3` or `^8.4` |
| Laravel | 11.x, 12.x or 13.x (`illuminate/contracts` + `illuminate/database` `^11.0\|\|^12.0\|\|^13.0`) |
| SQLite driver | `pdo_sqlite` - required by the default `Zipavlin\Redline\Providers\Storage\DatabaseStorageProvider` |
| Optional | `ryangjchandler/orbit` (Laravel 12+ only) - required by `Zipavlin\Redline\Providers\Storage\FileStorageProvider` |

## Composer install

```bash
composer require zipavlin/redline
```

The package registers itself through Laravel's package auto-discovery (`extra.laravel.providers` → `Zipavlin\Redline\RedlineServiceProvider`), so no manual service-provider registration is needed. If auto-discovery is disabled in your app, add the provider to `bootstrap/providers.php` yourself.

> Everything the package offers is **not** active immediately after install: publishing, the artisan commands and the config are always registered, but routes and widget injection are gated by the configured guard (see [06-guards.md](06-guards.md)).

## Interactive setup - `php artisan redline:install`

The recommended path. The command walks you through storage, auth, guard, features, email, publishing, and the first admin user, writing the chosen values straight into `.env` (updating existing keys, appending new ones; if no `.env` is found it warns and skips the write). Provider values are stored as fully-qualified class names.

Check detailed information about each step in [08-commands.md](08-commands.md#redlineinstall)

1. **Storage provider** - *"Controls how data is saved."*
   - `Database (SQLite - recommended)` → `Zipavlin\Redline\Providers\Storage\DatabaseStorageProvider` (default selection)
   - `File (JSON files via ryangjchandler/orbit)` → `Zipavlin\Redline\Providers\Storage\FileStorageProvider`

   Writes `REDLINE_STORAGE_PROVIDER`.

   For the **Database** provider the installer then creates the schema itself: it makes sure `storage/app/redline/database/` exists, wires up the `redline` SQLite connection in-process, and runs

   ```bash
   php artisan migrate --path=vendor/zipavlin/redline/database/redline --database=redline
   ```

   which executes the package's numbered migrations `0001_`–`0005_` against `storage/app/redline/database/redline.db`.

   For the **File** provider, if `ryangjchandler/orbit` is not installed, you are offered `composer require ryangjchandler/orbit` (default: No). If you accept, the command installs it and asks you to **rerun `redline:install`**; if you decline, the rest of the setup continues and the first-admin step is skipped (see below).

2. **Auth provider** - *"Controls how users register and which roles they can claim."*

   | Option | Title shown | Extra prompts |
   |---|---|---|
   | Open (default) | Open (anyone can register, free role selection) | - |
   | Pin | PIN (secret code required to claim developer role) | Reporter PIN, then Developer PIN (each blank = skip; they must differ) |
   | Domain | Domain (restrict registration to allowed email domains) | Comma-separated allowed domains |
   | Approval | Approval (self-register → pending → admin approves) | - |
   | Closed | Closed (admin creates all users - no self-registration) | - |

   Writes `REDLINE_AUTH_PROVIDER`, plus `REDLINE_AUTH_PIN_REPORTER` / `REDLINE_AUTH_PIN_DEVELOPER` (Pin) or `REDLINE_AUTH_DOMAINS` (Domain) when values are given.

   > A sixth provider, `Zipavlin\Redline\Providers\Auth\CloudAuthProvider`, exists for the planned cloud storage mode but is **not offered** by the installer. Do not select it manually.

3. **Guard provider** - *"Controls when Redline (widget and dashboard) is loaded."*

   | Option | Title shown | Extra prompts |
   |---|---|---|
   | Environment (default) | Environment (active when APP_ENV=staging) | - |
   | Debug | Debug (active when APP_DEBUG=true) | - |
   | IP | IP (active when client IP is in REDLINE_GUARD_ALLOWED_IPS) | Comma-separated allowed IPs |

   Writes `REDLINE_GUARD_PROVIDER`, plus `REDLINE_GUARD_ALLOWED_IPS` for the IP guard.

4. **Features** - *"All features are enabled by default. Select any you want to disable."* Multi-select from `screenshots`, `videos`, `files`, `comments`; pressing Enter without a selection disables nothing. Writes `REDLINE_DISABLED` only when something was selected.

5. **Email setup** - skipped entirely when a real mailer is already configured (default mailer is not `log`/`array`/`null` and `REDLINE_MAIL` is not `false`). Otherwise it warns - if the chosen auth provider verifies users by email (the **Domain** provider does), registration verification and password reset stay dead until a mailer exists; otherwise emails (password reset, new-report notifications) are simply skipped. Then it offers:

   | Option | What it does |
   |---|---|
   | Resend (API) - requires verifying a sending domain (DNS) | Installs `resend/resend-php`, sets `MAIL_MAILER=resend`, prompts for the API key → `RESEND_API_KEY`. Requires a DNS-verified sending domain; without one only the account's own signup email works. Laravel 11+ ships the needed `resend` entry in `config/services.php`. |
   | SMTP without DNS (recommended for staging - e.g. Mailjet) | Prompts for host (default `in-v3.mailjet.com`), port (default `587`), username, password, from address, from name (default `Redline`). Writes `MAIL_MAILER=smtp`, `MAIL_HOST`, `MAIL_PORT`, `MAIL_USERNAME`, `MAIL_PASSWORD`, `MAIL_FROM_ADDRESS`, `MAIL_FROM_NAME`. |
   | Skip - I'll configure a mailer later | Nothing written. Configure any Laravel mailer later, or set `REDLINE_MAIL=false` to silence the check. |

   Default selection: **Resend** when the chosen auth provider requires email verification, otherwise **Skip**. SMTP guidance includes a Mailjet walkthrough (sender verification via inbox link, no DNS) and notes alternatives (Brevo, Postmark, Mailtrap - testing only, mail never delivered).

6. **Publish config & assets**
   - *"Publish Redline config? This is not needed - you can set Redline in .env"* (default: **No**) - runs `vendor:publish --tag=redline-config`, overwriting only after a second confirmation if `config/redline.php` already exists.
   - *"Publish Redline assets to public/vendor/redline/?"* (default: **Yes**) - runs `vendor:publish --tag=redline-assets`.

7. **First admin user** - *"Would you like to create the first admin user?"* (default: **Yes**) - runs `php artisan redline:user` (password minimum 6 characters). Skipped with a hint to run `composer require ryangjchandler/orbit && php artisan redline:user` when File storage was chosen without orbit installed.

8. **Done** - prints next steps: set `APP_ENV=staging` (and `APP_DEBUG=true`) on the staging server, deploy, and visit `/redline/dashboard` to review reports.

> The installer's hint mentions `APP_DEBUG=true`, but the default `Zipavlin\Redline\Providers\Guard\EnvironmentGuardProvider` only checks `APP_ENV=staging`. `APP_DEBUG` matters only if you pick the Debug guard. See [06-guards.md](06-guards.md).

### `.env` variables written by the installer

| Variable | Example value | Purpose |
|---|---|---|
| `REDLINE_STORAGE_PROVIDER` | `Zipavlin\Redline\Providers\Storage\DatabaseStorageProvider` | Storage backend FQCN |
| `REDLINE_AUTH_PROVIDER` | `Zipavlin\Redline\Providers\Auth\OpenAuthProvider` | Auth provider FQCN |
| `REDLINE_AUTH_PIN_REPORTER` / `REDLINE_AUTH_PIN_DEVELOPER` | - | PIN per role (Pin provider only) |
| `REDLINE_AUTH_DOMAINS` | `example.com,acme.org` | Allowed domains (Domain provider only) |
| `REDLINE_GUARD_PROVIDER` | `Zipavlin\Redline\Providers\Guard\EnvironmentGuardProvider` | Guard provider FQCN |
| `REDLINE_GUARD_ALLOWED_IPS` | `127.0.0.1` | Allowed IPs (IP guard only) |
| `REDLINE_DISABLED` | `videos,files` | Features to disable |
| `MAIL_MAILER` / `MAIL_HOST` / `MAIL_PORT` / `MAIL_USERNAME` / `MAIL_PASSWORD` / `MAIL_FROM_ADDRESS` / `MAIL_FROM_NAME` | - | Mailer config (Resend/SMTP step only) |
| `RESEND_API_KEY` | `re_…` | Resend API key (Resend step only) |

## Manual publishing

Only needed if you skip the installer and configure everything through `.env` yourself.

| Command | Publishes |
|---|---|
| `php artisan vendor:publish --tag=redline-config` | `config/redline.php` |
| `php artisan vendor:publish --tag=redline-assets` | `public/vendor/redline/` (widget + dashboard JS/CSS bundles) |
| `php artisan vendor:publish --tag=redline-mail-views` | `resources/views/vendor/redline/mail` (editable mail templates) |

These three tags are available in **every** environment - they are not gated by the guard. If you publish the config, remember that every `env()` call in it can still be overridden from `.env`; many setups skip `redline-config` entirely and configure Redline only through environment variables.

> For the **Database** provider, the schema is **not** created automatically at runtime - the installer runs the migrations for you. If you configured storage manually and the tables are missing, the storage layer throws a `RuntimeException` telling you to run `php artisan redline:install`.

## Optional file storage

The `Zipavlin\Redline\Providers\Storage\FileStorageProvider` keeps reports in JSON files via Orbit instead of SQLite. It requires **Laravel 12+** and the `ryangjchandler/orbit` package, which is listed as `suggest` in `composer.json` (installed separately, or let `redline:install` install it for you):

```bash
composer require ryangjchandler/orbit
```

See [04-storage.md](04-storage.md) for the trade-offs between the two backends.

## Post-install checklist

- **Ignore the data directory** - add `storage/app/redline/` to `.gitignore` so report data, uploaded files and the SQLite database never reach the repository.
- **Seed the first admin** - if you skipped the first-user step, run `php artisan redline:user`. `REDLINE_AUTH_ALLOW_SELF_ADMIN` defaults to `false`, so public registration can never create an admin.
- **Check the guard** - confirm `REDLINE_GUARD_PROVIDER` matches your intent (default `Zipavlin\Redline\Providers\Guard\EnvironmentGuardProvider`, active only when `APP_ENV=staging`). If some staging routes should never show the widget, set `REDLINE_GUARD_EXCLUDE` (see [06-guards.md](06-guards.md)).
- **Verify activation on staging** - deploy with `APP_ENV=staging` and confirm the widget injects on a test page and `/redline/dashboard` loads. If nothing appears, check the guard config first - the widget/API are gated; the dashboard shell is not.
- **Mail** - if you skipped email setup, either configure a Laravel mailer or accept that password-reset and new-report emails are silently skipped (set `REDLINE_MAIL=false` to disable them entirely; see [09-emails.md](09-emails.md)).

## Uninstalling

```bash
php artisan redline:remove
```

`redline:remove` wipes everything Redline-related. It asks you to type the app name (derived from `app.url` - the first hostname label, falling back to `redline`) to confirm, or pass `--force` to skip the prompt. It then, in order:

1. **Removes storage** - deletes `storage/app/redline/` (refuses to delete anything outside `storage/app`).
2. **Removes published assets** - `public/vendor/redline/`.
3. **Removes published config and mail views** - `config/redline.php` and `resources/views/vendor/redline/`.
4. **Strips `REDLINE_*` entries** from `.env`.
5. **Offers to remove the Orbit cache** - only when File storage + Orbit are in use.
6. **Scans for manual clean-ups** - searches your project's `*.php` files (excluding `vendor`, `node_modules`, `storage`, `bootstrap/cache`) for `ShowRedlineWidget`, `Zipavlin\Redline` and `RedlineServiceProvider` references and lists each match for you to remove by hand (e.g. a manually registered provider in `bootstrap/providers.php`).
7. **Optionally removes the package** - asks whether to run `composer remove zipavlin/redline`; with `--force` the prompt is skipped and the package is removed automatically. If the automatic removal fails it prints the manual `composer remove` command.

After it finishes, check your app boots cleanly without the package.

## Related

- [01-introduction.md](01-introduction.md) - what Redline is and how it fits together
- [03-configuration.md](03-configuration.md) - every environment variable and config key
- [04-storage.md](04-storage.md) - storage backends (SQLite vs. file)
- [05-authentication.md](05-authentication.md) - auth providers, JWT, CSRF
- [06-guards.md](06-guards.md) - when the widget and API are active
- [08-commands.md](08-commands.md) - all artisan commands
- [09-emails.md](09-emails.md) - mail behavior and templates
- [api.md](07-resources.md) - the API resources
- [11-widget-dashboard.md](11-widget-dashboard.md) - the Blade shells and assets
