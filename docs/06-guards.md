# Guards

Redline's **guard** decides *when* the package is active at all. The boot process and every request consult a single `Zipavlin\Redline\Providers\Guard\GuardProvider` instance, selected through configuration, whose `isActive()` answer switches the package's request-facing surface on or off.

This chapter covers the three built-in providers, how to exclude individual paths from widget/API injection, how dashboard and widget gating differ, and how to write your own guard.

| Variable | Default | Purpose |
|---|---|---|
| `REDLINE_GUARD_PROVIDER` | `Zipavlin\Redline\Providers\Guard\EnvironmentGuardProvider` | Fully-qualified class name of the active guard provider |
| `REDLINE_GUARD_EXCLUDE` | `null` (nothing excluded) | Comma-separated paths/globs where the widget and API are skipped |
| `REDLINE_GUARD_ALLOWED_IPS` | `''` (empty → never active) | Comma-separated IP allowlist for `IpGuardProvider` |

## What a guard controls

Part of the package is registered unconditionally; the guard only governs the request-facing surface:

| Capability | Always registered? | Gated by |
|---|---|---|
| `vendor:publish` tags (`redline-assets`, `redline-config`, `redline-mail-views`) | Yes | - |
| Artisan commands (`redline:install`, `redline:user`, `redline:remove`) | Yes (console) | - |
| Dashboard SPA at `/{redline.path}` (default `/redline`) | No | `isActive` **and** request path starts with `redline.path` **and** storage is local |
| API routes at `/{redline.path}/api/...` | No | `isActive` **and** not excluded **and** storage is local |
| Widget script injection into `web` responses | No | `isActive` **and** not excluded (re-checked per request) |

When the guard is **inactive**, `RedlineServiceProvider::boot()` returns early right after registering publishing and commands: no storage provider boots, no views are loaded, no routes are registered, and `ShowRedlineWidget` is never appended to the `web` group. From the host app's point of view the package has no web surface at all.

When the guard is **active**, two *independent* per-request decisions determine what loads (see [Dashboard vs widget gating](#dashboard-vs-widget-gating)). The key nuance: **exclusion applies only to the widget + API, never to the dashboard** - an active guard with an excluded path still serves the dashboard at `/redline`.

## EnvironmentGuardProvider

FQCN: `Zipavlin\Redline\Providers\Guard\EnvironmentGuardProvider` - the **default**.

- **Activation:** `app()->environment('staging')` - active exactly when `APP_ENV=staging`, for every request in that environment.
- **Env knobs:** none beyond `APP_ENV` itself; this is the default value of `REDLINE_GUARD_PROVIDER`.
- **Intended use:** the default for the package's core workflow - a staging environment where the widget and dashboard should always be present.

> **`APP_DEBUG` does NOT gate the default guard.** The comment in the published `config/redline.php` claims the default is "`APP_ENV=staging AND APP_DEBUG=true`", but that comment is stale. The code checks the environment only (`app()->environment('staging')`); `APP_DEBUG` is irrelevant to `EnvironmentGuardProvider`. The suite confirms this (`tests/Guard/EnvironmentGuardProviderTest.php`): the guard stays active on staging with `APP_DEBUG=false`, and is inactive off staging with `APP_DEBUG=true`. If you want debug-based gating, switch to `DebugGuardProvider`.

## DebugGuardProvider

FQCN: `Zipavlin\Redline\Providers\Guard\DebugGuardProvider`.

- **Activation:** `config('app.debug') === true` - active when `APP_DEBUG=true`.
- **Env knobs:** selected via `REDLINE_GUARD_PROVIDER`.
- **Intended use:** local development and any environment where debug mode is on, regardless of `APP_ENV` - handy on dev machines or preview deployments where you want Redline available without touching environment names.

```dotenv
REDLINE_GUARD_PROVIDER=Zipavlin\Redline\Providers\Guard\DebugGuardProvider
```

## IpGuardProvider

FQCN: `Zipavlin\Redline\Providers\Guard\IpGuardProvider`.

- **Activation:** active when the request's client-IP chain (as returned by `$request->getClientIps()`) intersects the allowlist `REDLINE_GUARD_ALLOWED_IPS` (comma-separated).
- **Env knobs:** `REDLINE_GUARD_ALLOWED_IPS`. This variable is read **directly via `env()`** and is **not** present in the published `config/redline.php` - it must be set as a real environment variable.
- **Intended use:** selective activation - keep Redline dark for the general public but live for your QA/testers' IPs, or expose it on a production-like host reachable only by the test team.
- **Caveats:** with the variable empty or unset the allowlist is empty and the guard is **never active**. Because the check runs against the full IP chain, an allowed `X-Forwarded-For` entry (behind a proxy or load balancer) also activates it.

```dotenv
REDLINE_GUARD_ALLOWED_IPS=203.0.113.10,203.0.113.20
```

## Excluding paths (REDLINE_GUARD_EXCLUDE)

`REDLINE_GUARD_EXCLUDE` is a comma-separated list of path patterns where the **widget and API are skipped** - the dashboard is never affected (see [Dashboard vs widget gating](#dashboard-vs-widget-gating)). It only matters while the guard is active; an inactive guard has everything off anyway.

Patterns and request paths are compared **without leading/trailing slashes**, and each pattern is trimmed the same way. There are three pattern forms:

| Form | Matches | Examples |
|---|---|---|
| `path` (no `*`) | the exact path **or any subpath** | `admin` → `/admin`, `/admin/users` - but NOT `/administrator` |
| `path/*` (trailing `/*`) | **subpaths only** - the parent is *not* excluded | `admin/*` → `/admin/users`, `/admin/users/5/edit` - but NOT `/admin` |
| glob (any other `*`) | `fnmatch()` glob against the whole path | `reports-*` → `/reports-2024` - but NOT `/reports/2024` |

Concrete setups:

```dotenv
# Exact + subpath: hide the whole admin area from the widget and API
REDLINE_GUARD_EXCLUDE=admin

# Subpath only: hide admin subpages but keep /admin itself widget-visible
REDLINE_GUARD_EXCLUDE=admin/*

# Glob: exclude any path matching the pattern
REDLINE_GUARD_EXCLUDE=reports-*

# Several patterns at once - any match excludes
REDLINE_GUARD_EXCLUDE=admin/*,reports-*,checkout
```

Two details worth knowing:

- The `/*` form deliberately leaves the parent live: `admin/*` excludes `/admin/users` but not `/admin` itself - the opposite of the no-wildcard form, which covers both.
- The no-wildcard form requires a `/` boundary: `admin` does not match `/administrator` (the exact comparison fails, and the subpath check is `admin/`-prefixed).

## Dashboard vs widget gating

Both decisions derive from the same `isActive()` answer but use different path logic. Both methods on `GuardProvider` are `final`:

- `shouldShowWidget(Request)` = `isActive` **AND NOT** `isExcluded` - gates the API routes and the `ShowRedlineWidget` middleware append.
- `shouldShowDashboard(Request)` = `isActive` **AND** `isDashboard` - where `isDashboard` is a plain prefix check: the request path starts with `config('redline.path')` (default `redline`).

Consequences:

- **Active + excluded:** the dashboard at `/redline` is still served; the widget and `/redline/api/*` are not. Exclusion never "hides" the dashboard.
- **Active + not excluded:** both the dashboard and the widget/API load (provided the storage provider is local - cloud storage defers the routes to cloud hosting; see [Storage](04-storage.md)).
- The dashboard prefix check has no trailing-slash boundary, but since the dashboard route itself is registered under the `redline` prefix, only `/redline` and its subpaths are ever served.
- `ShowRedlineWidget` re-checks `shouldShowWidget()` on every request as a safety net (the boot-time check already gates registration), and only injects into `text/html` responses that contain a `</body>` tag.

## Custom guard providers

Write your own guard by extending `Zipavlin\Redline\Providers\Guard\GuardProvider` and implementing `isActive(Request $request): bool` - any condition is allowed: a feature flag, a subdomain, a request header, a schedule, and so on.

```php
<?php

namespace App\Redline;

use Illuminate\Http\Request;
use Zipavlin\Redline\Contracts\Listable;
use Zipavlin\Redline\Providers\Guard\GuardProvider;

class StagingSubdomainGuard extends GuardProvider implements Listable
{
    public function isActive(Request $request): bool
    {
        return str_ends_with($request->getHost(), '.staging.example.com');
    }

    public static function title(): string
    {
        return 'Staging subdomain (*.staging.example.com)';
    }

    public static function position(): int
    {
        return 4;
    }
}
```

Bind it via `REDLINE_GUARD_PROVIDER` (or the published `config/redline.php` → `redline.guard.provider`):

```dotenv
REDLINE_GUARD_PROVIDER=App\Redline\StagingSubdomainGuard
```

Guidelines:

- The provider is constructed with `new $class` as a singleton, with **no constructor arguments** - keep the constructor parameterless and resolve any dependencies with `app()` inside `isActive()`.
- `shouldShowWidget()` and `shouldShowDashboard()` are `final`; override only `isActive()`. The protected `isExcluded()` / `isDashboard()` helpers can be overridden if you need different path semantics.
- Implement `Zipavlin\Redline\Contracts\Listable` (`title(): string`, `position(): int`, both static) so your guard appears by name in the `redline:install` guard chooser - optional, but keeps the interactive installer accurate.

## Custom Guard Provider

- Extend `Zipavlin\Redline\Providers\Guard\GuardProvider` and register it in `config.redline.guard.provider` or `REDLINE_GUARD_PROVIDER`.

## Related

- [Introduction](01-introduction.md)
- [Installation](02-installation.md)
- [Configuration](03-configuration.md)
- [Storage](04-storage.md)
- [Authentication](05-authentication.md)
- [Resources](07-resources.md)
- [Commands](08-commands.md)
