# Introduction

Redline is a self-hosted, on-page bug-reporting tool for staging environments. Testers
can pin comments, annotate screenshots, record videos, and view existing pins without
leaving the page; developers and admins review and resolve the resulting reports in a
built-in dashboard. All data lives in your own storage.

This package - **`zipavlin/redline`** - is the Laravel backend/API provider for Redline.
It is not a standalone application: it installs into a host Laravel app and contributes:

- **Storage** - reports, users, comments, and attachments persist in your own storage:
  an SQLite database (the package's own Eloquent connection).
- **Authentication** - JWT-based auth (HS256, signed with `app.key`), delivered as an
  HttpOnly `redline_token` cookie, with double-submit CSRF protection for state-changing
  requests.
- **Guard-gated activation** - routes, widget injection, and the dashboard shell are
  switched on by a pluggable guard (default: `EnvironmentGuardProvider`, active when
  `APP_ENV=staging`).
- **REST API** - a generic query grammar (`filter[]`, `include[]`, `sort[]`,
  `paginate[]`, `aggregate[]`, `group_by[]`) over reports, users, comments, and
  attachments.
- **Blade shells** - `widget.blade.php` and `dashboard.blade.php` mount the frontend
  apps.
- **Committed built assets** - production bundles of the widget and dashboard are
  committed under `public/` and published with
  `php artisan vendor:publish --tag=redline-assets`.

The widget and dashboard UI themselves are **not** built from this repository: all
frontend source (Vue 3 + TypeScript + Vite) lives in the separate
[redline-app](https://github.com/zipavlin/redline-app) project, and this package ships
its built output.

> The package is **not** "completely inert outside staging" - only parts of it are
> gated. See [Activation model](#activation-model).

## Features

For testers, on any page of a staging app:

- **Pin comments** - attach a comment to a specific spot on the page.
- **Annotated screenshots** - capture and draw on the current viewport.
- **Screen recording** - record a video of the interaction leading up to a bug.
- **View existing pins** - see what has already been reported, without leaving the page.

For developers and admins, a built-in **dashboard** covering reports, users, and
analytics, where reports flow through the lifecycle `new → noticed → progress →
resolved → done`.

Features are individually switchable. Four flags - `screenshots`, `videos`, `files`,
and `comments` - are all **on by default** and can be turned off with the
`REDLINE_DISABLED` environment variable (comma-separated list). The frontend learns
which features are enabled from the public bootstrap endpoint at mount time, not from
element attributes.

## Requirements

| Component | Requirement |
|---|---|
| PHP | 8.3 or 8.4 (`^8.3\|^8.4`) |
| Laravel | 11.x, 12.x, or 13.x |
| Extension | `pdo_sqlite` (required by the storage backend) |

## Activation model

Only parts of the package are gated by environment:

- **Always available** - `vendor:publish` tags (`redline-assets`, `redline-config`,
  `redline-mail-views`) and the three artisan commands (`redline:install`,
  `redline:user`, `redline:remove`).
- **Dashboard** - the SPA shell at `/{redline.path}` (default `/redline`) loads when
  the guard is active **and** the request path starts with the redline path.
- **API routes + widget injection** - require the guard to be active **and** the
  request path not excluded via `REDLINE_GUARD_EXCLUDE` (default: no exclusions).

The default guard is
`Zipavlin\Redline\Providers\Guard\EnvironmentGuardProvider`, which is active when
`APP_ENV=staging` - `APP_DEBUG` is **not** part of that condition. Alternatives are
`DebugGuardProvider` (`APP_DEBUG=true`) and `IpGuardProvider`
(`REDLINE_GUARD_ALLOWED_IPS`, env-only). See [Guards](06-guards.md).

> The dashboard, API routes, and widget injection are all wired up once the guard
> is active - there is a single local storage backend and no cloud mode yet.

## Related repositories

| Repository | Purpose |
|---|---|
| [zipavlin/redline-laravel](https://github.com/zipavlin/redline-laravel) | This package - the Laravel backend/API provider. |
| [zipavlin/redline-app](https://github.com/zipavlin/redline-app) | All frontend source (widget + dashboard), built and handed off into this package's `public/`. |

## License

This package is open-sourced under the [MIT license](LICENSE.md).

## Related

- [Installation](02-installation.md)
- [Configuration](03-configuration.md)
- [Storage](04-storage.md)
- [Authentication](05-authentication.md)
- [Guards](06-guards.md)
- [API / Resources](07-resources.md)
- [Artisan commands](08-commands.md)
- [Emails](09-emails.md)
- [Widget & dashboard](11-widget-dashboard.md)
