# Resources - Redline API Reference

The HTTP API exposed by the `zipavlin/redline` Laravel package. Every route below is
defined in `routes/api.php`; this chapter is the drift-check twin of the query-grammar
docblock on `Zipavlin\Redline\Http\Query\QuerySchema` and of the controllers in
`src/Http/Controllers/Api/`. If a statement here disagrees with the code, the code wins -
and the docblock should be regenerated from `QuerySchema` rather than hand-patched.

## Overview

### Base path and versioning

All routes are registered under a single prefix:

```
/{redline.path}/api
```

`redline.path` comes from `config('redline.path')` (default `redline`), so the default
base path is `/redline/api`. There is **no versioned URL prefix** and no `Accept`-based
API versioning. The bootstrap endpoint returns a `version` field (currently the constant
`1`) - that is the capability-manifest version, not the package version. The composer
package version is used only for asset cache-busting (`RedlineServiceProvider::getVersion()`
via Composer InstalledVersions), never in the URL.

### Authentication contract (summary)

See [Authentication](05-authentication.md) for the full contract; the essentials:

- Same-origin browser clients authenticate with the **HttpOnly `redline_token` cookie**
  (JWT, HS256, secret = `config('app.key')`). State-changing cookie-authenticated requests
  must echo the non-HttpOnly `redline_csrf` cookie in the **`X-Redline-CSRF`** header
  (double-submit CSRF). Cookie and header names are configurable and reported by bootstrap
  (`csrf.cookie` / `csrf.header`).
- Cross-origin clients (e.g. a widget embedded on a third-party origin) send
  `Authorization: Bearer <jwt>` and **skip CSRF**. Bearer takes precedence when both are
  present.
- JWT invalidation: every JWT carries `ver` (the user's `token_version`); logout, password
  change, and admin password reset bump `token_version`, rejecting all previously issued
  tokens.
- **Guest mode** (`REDLINE_AUTH_GUEST=true`): `POST /redline/api/report` may be sent
  **unauthenticated**, attributed to the shared Guest user (`guest@redline.local`). Such
  submissions are gated by the `redline-guest` rate limiter and, when
  `REDLINE_AUTH_GUEST_ORIGINS` is set, by an Origin allowlist.

### Rate limiters

| Limiter | Default | Applies to | Config |
|---|---|---|---|
| `redline-auth` | 10/min per IP | `auth/register`, `auth/login`, `auth/verify`, `auth/forgot-password`, `auth/reset-password` | `REDLINE_AUTH_THROTTLE` |
| `redline-bootstrap` | 120/min per IP | `bootstrap` | `REDLINE_BOOTSTRAP_THROTTLE` |
| `redline-guest` | 20/min per IP | anonymous `POST report` (guest mode only) | `REDLINE_AUTH_GUEST_THROTTLE` |

All protected routes (`auth/me`, `auth/refresh`, `auth` DELETE, and everything under
Reports/Users) are **not** rate-limited. Exceeding a limit returns HTTP 429 with a
`Retry-After` header.

### Protected-route middleware

Every protected route runs `RedlineApiAuth` (JWT verification + user resolution) and
`RedlineCsrf` (double-submit check on state-changing cookie-authenticated requests).
`RedlineApiAuth` sets `redline_auth_source` to `'bearer'` or `'cookie'` so the CSRF
middleware can enforce the rule "cookie auth ⇒ CSRF required, bearer auth ⇒ skipped".

## Authentication endpoints

Auth public endpoints are rate-limited by `redline-auth`. Response cookies: success on
register/login/verify/refresh sets the HttpOnly `redline_token` cookie plus the readable
`redline_csrf` cookie; `DELETE auth` expires both.

### `POST /auth/register`

```
POST /{redline.path}/api/auth/register
```

Create an account. Which fields are required/optional is **provider-defined** - the
provider's `validate()` runs on the whole payload; the authoritative field list is exposed
by bootstrap as `capabilities.auth.fields`. With the default `OpenAuthProvider` the
payload is roughly `{ name, email, password, role }`; with `REDLINE_AUTH_ALLOW_SELF_ADMIN`
left at `false`, `role: admin` is rejected for public registration.

Responses:

- `201` - `{ "user": { ... } }` plus auth cookies. If the provider requires approval or
  email verification the account is created as `pending` instead and the response is:
- `202` - `{ "errors": ["..."], "pending": true }` (account created but not yet active;
  e.g. "Registration submitted, awaiting approval.").
- `403` - `{ "errors": ["Registration not allowed."] }` when the provider's
  `canRegister()` is false.
- `422` - `{ "errors": { "<field>": ["..."] } }` provider validation failures.

### `POST /auth/login`

```
POST /{redline.path}/api/auth/login
```

Body: `{ "email": string, "password": string }`, optional `"remember": bool` (alias
`"remember_me"`). Remembered sessions use `REDLINE_AUTH_REMEMBER_TOKEN` TTL (default
2592000 s); normal sessions use `REDLINE_AUTH_TOKEN` (default 3600 s).

Responses:

- `200` - `{ "user": { ... } }` plus auth cookies.
- `202` - `{ "errors": ["..."], "pending": true }` when the account is still pending
  approval.
- `401` / `403` - `{ "errors": ["..."] }` on bad credentials or a provider that disallows
  login (`canLogin()` false → 403).
- `422` - validation errors for `email` / `password`.

### `GET /auth/verify`

```
GET /{redline.path}/api/auth/verify?signature=...&expires=...
```

Email-verification link. The **signed URL** is validated inside the controller via
`$request->hasValidSignature()` (not the `signed` middleware) so a browser following an
invalid/expired link gets a friendly HTML page instead of Laravel's 403. API clients
(requesting JSON via `Accept`) get the JSON envelope:

- `200` - `{ "user": { ... } }` plus auth cookies (account activated).
- `422` - `{ "errors": ["Invalid or expired verification link."] }`.
- `404` - `{ "errors": ["Verification not supported by this provider."] }`.

### `POST /auth/forgot-password`

```
POST /{redline.path}/api/auth/forgot-password
```

Body: `{ "email": string }`. Emails a signed reset link (valid 1 hour) pointing at the
dashboard reset page. **Always returns `200` `{ "ok": true }`** whether or not the email
exists - no account enumeration.

### `POST /auth/reset-password`

```
POST /{redline.path}/api/auth/reset-password?signature=...&expires=...
```

Protected by the `signed` middleware. Body: `{ "email": string, "password": string }`
(`password` min 8). Sets the new password and bumps `token_version` (invalidating all
outstanding JWTs).

- `200` - `{ "ok": true }`.
- `422` - `{ "errors": ["Invalid or expired reset link."] }` or validation errors.
- `403` - invalid/expired signature (Laravel's signed-middleware failure).

### `DELETE /auth` (protected)

```
DELETE /{redline.path}/api/auth
```

Logout. Bumps `token_version` (all outstanding JWTs become invalid) and expires both the
`redline_token` and `redline_csrf` cookies with their original attributes.

- `200` - `{ "message": "Logged out." }`

### `GET /auth/me` (protected)

```
GET /{redline.path}/api/auth/me
```

- `200` - `{ "user": { ... } }` (full user model; `password` / `token_version` always
  hidden).

### `GET /auth/refresh` (protected)

```
GET /{redline.path}/api/auth/refresh
```

Issues a fresh token (and cookies). The `remember` flag is read from the signature-verified
JWT payload attached by `RedlineApiAuth` - never from client input.

- `200` - `{ "user": { ... } }` plus refreshed auth cookies.

## Reports

All report routes are protected. Route names: only the auth routes and `bootstrap` carry
`->name()`; the resource routes below are unnamed in `routes/api.php`.

| Method | Path | Controller action | Operation gate |
|---|---|---|---|
| GET | `/report/aggregate` | `ReportController@aggregate` | LIST |
| GET | `/report` | `ReportController@index` | LIST |
| POST | `/report` | `ReportController@store` | CREATE |
| GET | `/report/{report}` | `ReportController@show` | VIEW |
| PATCH | `/report/{report}` | `ReportController@update` | EDIT (author only) |
| DELETE | `/report/{report}` | `ReportController@destroy` | DELETE (own or admin) |
| POST | `/report/{report}/move` | `ReportController@move` | UPDATE (own or developer+) |
| PATCH | `/report/{report}/status` | `ReportController@status` | UPDATE + transition rules |
| POST | `/report/{report}/member` | `MemberController@store` | UPDATE (own or developer+) |
| DELETE | `/report/{report}/member` | `MemberController@destroy` | UPDATE (own or developer+) |
| GET | `/report/{report}/comment` | `CommentController@index` | LIST |
| POST | `/report/{report}/comment` | `CommentController@store` | CREATE (+ `comments` feature) |
| PATCH | `/report/{report}/comment/{comment}` | `CommentController@update` | EDIT (author only) |
| DELETE | `/report/{report}/comment/{comment}` | `CommentController@destroy` | DELETE (own or admin) |
| GET | `/report/{report}/screenshot` | `ScreenshotController@show` | VIEW |
| POST | `/report/{report}/screenshot` | `ScreenshotController@store` | UPDATE (own or developer+) (+ `screenshots` feature) |
| GET | `/report/{report}/screenshot/{screenshot}/binary` | `ScreenshotController@binary` | VIEW + parent check |
| GET | `/report/{report}/video` | `VideoController@show` | VIEW |
| POST | `/report/{report}/video` | `VideoController@store` | UPDATE (own or developer+) (+ `videos` feature) |
| GET | `/report/{report}/video/{video}/binary` | `VideoController@binary` | VIEW + parent check |

Operation gates come from `Zipavlin\Redline\Models\Base\User::can()`:
`UPDATE` = developer/admin **or** the report's author; `EDIT` = author only; `DELETE` =
admin or author; `LIST`/`VIEW`/`CREATE` = any approved role (plus Guest for `CREATE` in
guest mode). `{report}`, `{comment}`, `{screenshot}`, `{video}` are UUIDs; nested-child
routes also verify the child belongs to the parent report (mismatch → 404).

### `GET /report` and `GET /report/aggregate`

Grammar-driven collection endpoints - see [Query grammar](#query-grammar). All
authenticated roles see **all** reports (no ownership scoping); `filter[mine]=1` narrows
to the caller's own. `index` accepts `filter[]`, `include[]`, `sort[]`, `paginate[]`;
`aggregate` accepts `filter[]`, `aggregate[]`, `group_by[]`.

> `browser` include is developer-only, and `user_agent` is stripped from it regardless of
> role - a privacy rule independent of `include[]`.

### `POST /report`

```
POST /{redline.path}/api/report
```

Submit a new report. In guest mode this is the one protected route callable without a
token (see [Overview](#overview)).

Validation (all fields optional unless marked):

| Field | Rules |
|---|---|
| `url` | required, string, max 2048 |
| `location` | required, string, max 255 |
| `body` | required, string (rich HTML; sanitized against an allowlist on write) |
| `pin` | nullable array |
| `pin.x` | nullable numeric 0–100 |
| `pin.y` | nullable numeric 0–100 |
| `pin.prefer_selector` | nullable boolean |
| `pin.scroll_x` / `pin.scroll_y` | nullable numeric |
| `element` | nullable string |
| `browser` | nullable array |

The report is created with `status: new` and attributed to the authenticated user (or the
shared Guest user). `body` is HTML-sanitized server-side (allowlist: `p,br,strong,em,s,a,
ul,ol,li`, safe link schemes, `target=_blank`). Developers/admins (except the reporter)
are emailed a new-report notification when a mailer is configured; the mail send is
best-effort and never fails the request.

- `201` - `{ "id", "status", "created_at", ... }` the fresh report (full model).

### `GET /report/{report}`

Single report, shaped by `include[]` (`filter`/`sort`/`paginate`/`aggregate` are **not**
accepted here - sending them is a 422).

Side effect: when a **developer** (developer or admin) views a report whose status is
`new`, it is automatically advanced to `noticed`.

- `200` - the report resource.

### `PATCH /report/{report}`

Update **body text only**, author only - developers/admins must not rewrite someone
else's body.

- `200` - the updated report. `403` if not the author.

### `DELETE /report/{report}`

Delete the report **and its associated media files** (screenshot/video paths), own or
admin. The report's `redline_members` rows are removed by the foreign key's
`ON DELETE CASCADE`.

- `204` No Content.

### `POST /report/{report}/move`

Relocate the pin. Own or developer+.

| Field | Rules |
|---|---|
| `x`, `y` | required numeric |
| `scroll_x`, `scroll_y` | nullable numeric |
| `prefer_selector` | nullable boolean |

Replaces the report's whole `pin` object.

- `200` - the updated report.

### `PATCH /report/{report}/status`

Change status. The `UPDATE` gate lets owners and developers through; the transition is
then constrained by role **and** the lifecycle chain - see [Status lifecycle](#status-lifecycle).
Body: `{ "status": "new"|"noticed"|"progress"|"resolved"|"done" }`.

- `200` - the updated report.
- `403` - `"Invalid status transition."` when the role/lifecycle disallows it.
- `422` - invalid status value.

### `POST/DELETE /report/{report}/member`

```
POST   /{redline.path}/api/report/{report}/member
DELETE /{redline.path}/api/report/{report}/member
```

Body: `{ "user_ids": ["<uuid>", ...] }` - required, array, **1..100** ids (each a string
up to 255 chars). Both are gated by `Operation::UPDATE` on the report (developer/admin or
the report's author); everyone else gets `403`.

- `POST` is **additive + idempotent** (`syncWithoutDetaching`): only pairs that do not
  already exist are created, so re-posting the same list is a no-op, not a `409`.
- `DELETE` is idempotent (`detach`): removing a pair that does not exist is a no-op.
- Both return `200` with `{ "members": [ { "id", "name", "initials" }, ... ] }` - the
  report's **full current list**, sorted by `name` then `id` (a total order), so the
  client never has to refetch or reconcile.
- Unknown ids, **pending** users, and **guest** users are not eligible -> `422`
  (`"One or more user ids are unknown."`). `DELETE` does not validate existence - an
  unknown id is simply a no-op.

### Comments

- `GET /report/{report}/comment` - all comments for the report, oldest first
  (`orderBy('created_at')` asc), each with its `author` relation loaded. Plain array
  response (no envelope).
- `POST /report/{report}/comment` - `{ "body": string }` (HTML-sanitized). `201` with the
  comment (author relation loaded). Gated by the `comments` feature flag.
- `PATCH /report/{report}/comment/{comment}` - `{ "body": string }`, **author only**.
- `DELETE /report/{report}/comment/{comment}` - own or admin. `204` No Content.

### Screenshots

- `GET /report/{report}/screenshot` - the report's screenshot row, or
  `404` `{ "message": "Screenshot not found." }`.
- `POST /report/{report}/screenshot` - upload **PNG, max 5 MB** (multipart field
  `screenshot`; validation `mimes:png`, `max:5120` KB). **One media per report**:
  a second upload returns `409` `{ "message": "Screenshot already exists for this report." }`.
  The file is stored as `{uuid}.png` and the screenshot row carries the report's `id`
  (`Str::orderedUuid()`). `201` with the screenshot row.
- `GET /report/{report}/screenshot/{screenshot}/binary` - serves the stored file bytes
  (`BinaryFileResponse`). **Media is only ever served through this route**; no public
  storage URL is exposed. 404 if the screenshot belongs to a different report.

### Videos

- `GET /report/{report}/video` - the report's video row, or
  `404` `{ "message": "Video not found." }`.
- `POST /report/{report}/video` - upload video **max 50 MB** (multipart field `video`;
  validation `file`, `max:51200` KB). **One media per report**: a second upload returns
  `409` `{ "message": "Video already exists for this report." }`. The stored extension is
  derived from the MIME type: `video/mp4`, `video/quicktime`, `video/x-m4v` → `mp4`,
  anything else → `webm`; the file is stored as `{uuid}.{ext}`. `201` with the video row.
- `GET /report/{report}/video/{video}/binary` - serves the stored file bytes. 404 if the
  video belongs to a different report.

> There is no generic file-upload endpoint. The `files` feature name exists in the
> feature enum/config but has no corresponding route in `routes/api.php`; only
> screenshots and videos have endpoints.

## Users

All user endpoints are **admin-only** (non-admin → `403` `"Admin access required."`).

| Method | Path | Action | Notes |
|---|---|---|---|
| GET | `/user/aggregate` | `UserController@aggregate` | tab counts |
| GET | `/user` | `UserController@index` | grammar-driven list |
| POST | `/user` | `UserController@store` | create user (admin) |
| GET | `/user/{user}` | `UserController@show` | single user, include-shaped |
| PATCH | `/user/{user}` | `UserController@update` | edit name/email/role/status |
| DELETE | `/user/{user}` | `UserController@destroy` | delete user |
| POST | `/user/{user}/approve` | `UserController@approve` | approve pending registration |
| POST | `/user/{user}/reset-password` | `UserController@resetPassword` | force password reset |

- `GET /user` - grammar-driven (`filter[]`, `include[]`, `sort[]`, `paginate[]`); see
  [Query grammar](#query-grammar) for the user whitelist.
- `GET /user/aggregate` - tab counts, e.g. the frontend sends
  `?aggregate[status:count]=approved,pending&filter[search]=...&filter[role]=...`.
  Accepts `filter[]` + `aggregate[]` only.
- `POST /user` - `{ "name", "email", "role" }` (`role` ∈ `reporter|developer|admin`),
  `"password"` optional **only if a mailer is configured** (a signed set-password
  invitation email is sent instead); without a mailer `password` is required (min 8).
  `201` returns the user row plus `invitation_sent` (bool) and `invitation_error`
  (`"mail_not_configured"` | `"send_failed"` | null). A failed mail never fails the
  request; the user is still created with `status: approved`.
- `PATCH /user/{user}` - partial update of `name`, `email`, `role`, `status`
  (`approved|pending`). Approving a pending user emails a `RegistrationApprovedMail`.
- `DELETE /user/{user}` - `204`. Cannot delete the **last remaining admin** (422). The
  user's `redline_members` rows are removed by the foreign key's `ON DELETE CASCADE`.
- `POST /user/{user}/approve` - sets `status: approved`; emails approval notification
  when the user was pending.
- `POST /user/{user}/reset-password` - `{ "password": string (min 8) }`; bumps the
  user's `token_version` (all their JWTs die). `200` `{ "ok": true }`.

Guards: `update`/`destroy` refuse to demote or deactivate the last remaining admin
(`422 "Cannot demote or deactivate the last remaining admin."`). All admin
user-management actions write a `redline.audit` log line (action, actor, target, IP).

## Bootstrap

```
GET /{redline.path}/api/bootstrap
```

Public - **no auth, no CSRF** - and rate-limited by `redline-bootstrap`. The widget and
dashboard fetch it at mount (before any login exists) to learn which features and auth
capabilities are enabled. No secrets or request data: only capability flags and config
names.

Response (`200`):

```json
{
  "version": 1,
  "features": ["screenshots", "videos", "files", "comments"],
  "capabilities": {
    "guest_reporting": false,
    "auth": {
      "can_login": true,
      "can_register": true,
      "fields": ["name", "email", "password", "role"],
      "can_select_role": true,
      "requires_approval": false,
      "requires_email_verification": false
    },
    "mail": {
      "configured": false
    }
  },
  "csrf": {
    "cookie": "redline_csrf",
    "header": "X-Redline-CSRF"
  }
}
```

Field semantics:

- `version` - capability-manifest version (constant `1`).
- `features` - enabled feature names: all of `screenshots`, `videos`, `files`, `comments`
  minus whatever `REDLINE_DISABLED` / `config('redline.disabled')` removes. The widget
  hides the corresponding capture modes.
- `capabilities.guest_reporting` - `REDLINE_AUTH_GUEST` (default false).
- `capabilities.auth.*` - flags from the active `AuthProvider`: whether login and public
  registration are allowed, the registration form fields (`fields`), whether the caller
  can pick their own role (`can_select_role`), whether new accounts wait for admin
  approval (`requires_approval`), whether email verification is required
  (`requires_email_verification`). The frontend derives the identity modal and login/register
  forms from these - **not** from element attributes.
- `capabilities.mail.configured` - true only when the default mailer is a real transport
  (not `log`/`array`/`null`); gates whether password invitations can be sent.
- `csrf` - the actual cookie name and header name to use for double-submit CSRF, so
  clients never hard-code them.

## Query grammar

The canonical reference is the docblock on `Zipavlin\Redline\Http\Query\QuerySchema`
(lines 5–110); the text below reproduces it and pins the per-resource whitelists. Every
collection endpoint accepts the same parameter groups. The shape is always
`<group>[<target>[:<operator>]]=<value>`, where `<target>` is an attribute, a
relationship, or `relationship.attribute`. Omitting `:<operator>` means equality.

```http
GET /redline/api/report
  ?filter[status]=unresolved              # eq (default operator)
  &filter[body:like]=There is a bug       # value needs no escaping
  &filter[created_at:from]=2026-05-01     # inclusive lower bound
  &filter[created_at:to]=2026-07-31       # inclusive upper bound
  &filter[author]=<uuid>                  # bare relationship == its primary key
  &filter[author.id:in]=<uuid>,<uuid>     # comma-separated OR
  &filter[author.name:like]=Zig           # relation attribute - delegates to the
                                          #   related resource's OWN schema (see
                                          #   FilterField::$relatedSchema); NOT every
                                          #   column is reachable this way -
                                          #   filter[author.email:like] 422s
  &filter[comments:exists]=1              # relationship existence - 1/true or 0/false
                                          #   only, anything else 422s
  &include[]=pin
  &include[author]=id,name,initials       # relationship, explicit field list
  &include[]=comments:count               # projection -> comments_count
  &include[]=screenshot:exists            # projection -> screenshot_exists
  &sort[created_at]=desc                  # repeatable; key order = precedence
  &paginate[per_page]=16                  # omit entirely for every matching row
  &paginate[page]=1
```

### Groups

| Group | Meaning |
|---|---|
| `filter` | Constrain rows. Whitelisted per resource (`Report::querySchema()`). |
| `include` | Add attributes/relationships to **every** row. This is how the widget and dashboard get different payloads from one endpoint - the widget asks for `pin,element`, the dashboard for `comments:count,screenshot:exists`. There is no implicit per-client mode. Base row is always `id`, `status`, `created_at`; everything else - including `body` and `author` - is include-gated, same as any other field. |
| `sort` | `sort[<attribute>]=asc\|desc`. Repeat for multi-key; first key wins ties. Falls back to the resource's `$defaultSort` when omitted. |
| `paginate` | `paginate[per_page]` and `paginate[page]`. Omitting `per_page` **entirely** returns every matching row (bounded only by the optional `config('redline.api.max_rows')` ceiling, off by default) - an EXPLICIT `per_page` is still capped at 100. |

### Operators

| Group | Operators |
|---|---|
| `filter[]` | `eq` \| `not` \| `like` \| `in` \| `not_in` \| `from` \| `to` \| `gt` \| `gte` \| `lt` \| `lte` \| `null` \| `not_null` \| `exists` (relationship existence) |
| `include[]` | (bare) \| `count` (→ `<target>_count`) \| `exists` (→ `<target>_exists`) |
| `aggregate[]` | `count` \| `unique` |

Per-field operator surfaces (from `QueryOperator`): string targets get
`eq/not/like/in/not_in/null/not_null`; date targets add the range operators
`from/to/gt/gte/lt/lte`; relation targets get `eq/not/in/not_in/exists`; boolean-style
targets get `eq/not/null/not_null`. Sending an operator a field does not support is a 422.

### Status groups vs concrete statuses

The one subtle rule. `filter[status]` accepts either a **group** or a **concrete status**:

| Value | Expands to |
|---|---|
| `unresolved` | every open status - `new`, `noticed`, `progress` |
| `resolved` | every closed status - `resolved`, `done` |
| `done` | exactly `done` |

So `filter[status]=resolved` is a superset of `filter[status]=done`. Groups are named in
`Status::openValues()` / `Status::closedValues()`; concrete values in the `Status` enum.
Group expansion also applies inside `filter[status:in]=unresolved,resolved` and to
`aggregate[status:count]=unresolved,resolved` bucket keys. An unknown status or group
value is a 422 (`"Unknown status or status group [x]."`), never silently ignored.

### Aggregates live on their own endpoint

```
GET /redline/api/report/aggregate
  ?aggregate[status:count]=unresolved,resolved
  &aggregate[location:count]                     # count per distinct value
  &aggregate[author:unique]                      # distinct value set, not a scalar
  &filter[...]                                   # same filter grammar
  &group_by[]=status                             # nest a grouped-count by a 2nd column
```

Deliberately **not** a parameter on `index()`, for two reasons:

1. `index()`'s response type stays invariant, which generated clients depend on.
2. Aggregates often need *different* filters than the rows - the report tab counts must
   ignore `filter[status]` so tabs don't jump as you switch them. A separate request
   expresses that by simply omitting the filter.

`group_by[]` only affects the grouped-count form (a `count` with no explicit value list) -
it nests the result by a second column instead of one flat total per value.
`?aggregate[location:count]&group_by[]=status` returns
`{ "location": { "count": { "/a": { "new": 1, "resolved": 1 }, "/b": { "new": 1 } } } }`,
e.g. for a per-page status breakdown. Only **one** `group_by` column is supported (422
otherwise), and it must itself be a valid plain-column aggregate target.

### Response envelopes

```
index      { "data": [...], "meta": { "total" } }                              - unpaginated
           { "data": [...], "meta": { "total", "per_page", "current_page", "last_page" } } - paginated
show       { ...resource }
aggregate  { "<target>": { "<operator>": <scalar|object|array> } }
```

Unpaginated `meta` is just `{ "total" }` - no per_page/current_page/last_page, since there
is no pagination to describe. When `config('redline.api.max_rows')` is set, `meta.total`
still reflects the full filtered count while `data` is truncated.

### Error semantics

Unknown or non-whitelisted group/target/operator → **422**. Params are never silently
ignored; that is what makes this grammar safe to generate clients from. A group excluded
via `ResourceQuery::use()` 422s the same way if the caller sends it anyway (e.g. `filter[]`
on `show()`, `include[]` on `aggregate()`). All violations in one request are collected
into a single 422 (never failing on the first).

### Known bounds

- **One constraint per (target, operator) pair** - `filter[status]=a&filter[status]=b`
  keeps only the last. Use `:in` for OR and `:from`/`:to` for ranges.
- **No cross-target OR**: `filter[screenshot:exists]=1&filter[video:exists]=1` means
  "has both", not "has either" (two `filter[]`s are always AND-combined).
- `:exists` accepts only `1`/`true` (has) or `0`/`false` (doesn't have); anything else 422s
  rather than silently matching neither.

### Report resource whitelist (`Report::querySchema()`)

Filters:

| Target | Operators | Notes |
|---|---|---|
| `status` | string set (`eq`,`not`,`like`,`in`,`not_in`,`null`,`not_null`) | group or concrete value; group expansion at parse time |
| `body` | string set | column filter |
| `created_at` | date set (adds `from`,`to`,`gt`,`gte`,`lt`,`lte`) | inclusive bounds |
| `location` | string set | column filter |
| `author` | relation set (`eq`,`not`,`in`,`not_in`,`exists`) | bare = FK equality; `author.id:in=a,b`; `author.name:like` delegated to User's schema via `relatedFilters` (only `name` is reachable - `author.email:like` 422s) |
| `member` | relation set (`eq`,`not`,`in`,`not_in`,`exists`) | relation over the member pivot: bare/`member.id` = the related user's id; `:in`/`:not`/`:not_in` over that. `:not`/`:not_in` negate the **whole** membership predicate ("has no such member"), not an inner column comparison. `:exists` = has/doesn't have any member. **No nested attribute delegation** - `member.name:like` / `member.name:eq` 422s |
| `search` | `like` only | virtual OR-field: `body` OR `location` LIKE `%value%` |
| `mine` | `eq` only | truthy → `author_id = current user` |
| `screenshot` | `exists` only | has/doesn't have a screenshot |
| `video` | `exists` only | has/doesn't have a video |
| `comments` | `exists` only | has/doesn't have comments |

Sortable: `created_at`, `status`, `location`. Default sort: `created_at` desc, `id` desc
(the `id` tiebreaker makes it a total order so pagination pages never overlap/skip).

Includes:

| Target | Projection | Notes |
|---|---|---|
| `title` | column/accessor | derived: first non-empty body line, tags stripped, truncated ~60 chars |
| `body`, `pin`, `element`, `url`, `location` | columns | |
| `browser` | gated | developer-only (`isDeveloper`); `user_agent` always stripped |
| `author` | relation | attributes `id,name,email`, default `id,name` |
| `screenshot` | relation / `:exists` | attributes `id,url` |
| `video` | relation / `:exists` | attributes `id,url` |
| `comments` | relation / `:count` / `:exists` | fixed shape: `{ id, body, created_at, author: { id, name } \| null }` |
| `members` | relation / `:count` / `:exists` | the member pivot's users, fixed shape `{ id, name, initials }` sorted by name then id. Target name equals the Eloquent relation `members`, so `withCount`/`withExists` attach `members_count`/`members_exists` |

Aggregates:

| Target | Operators | Notes |
|---|---|---|
| `status` | `count` | value list + groups, e.g. `aggregate[status:count]=unresolved,resolved` |
| `location` | `count`, `unique` | `count` → per-distinct-value counts; `unique` → distinct `location` values, ordered |
| `author` | `count`, `unique` | `count` with no value list → `{ "<author-uuid>": count }`; `unique` → `[{ "id", "name" }]` ordered by name |

`group_by[]` column must be a plain-column aggregate target (`status` or `location`).

### User resource whitelist (`User::querySchema()`)

Serves the admin-only `/user` endpoints **and** relation-attribute delegation from
`filter[author.name:like]` on `/report`. The delegation boundary is
`FilterField::$relatedFilters` - declaring `email`/`role`/`status` here does **not** make
them reachable through `/report`'s `author` relation.

Filters: `search` (`like`; name OR email), `name` (string set), `email` (string set),
`role` (`eq`,`in`), `status` (`eq`,`in`).

Sortable: `name`, `created_at`, `reports_count`, `comments_count`. Default sort:
`name` asc, `id` asc.

Includes: `name`, `email`, `role`, `initials` (columns/accessor); `reports` (`:count`),
`comments` (`:count`); `stats` (resolver: `{ reports, open, resolved }` counts by status
group).

Aggregates: `status` (`count`).

## Status lifecycle

The report lifecycle is `new → noticed → progress → resolved → done`. The chain is
bidirectional and only adjacent steps are allowed, **except** that `progress` may be
skipped (`noticed ↔ resolved`):

```
new <-> noticed <-> progress <-> resolved <-> done
                 \___ noticed <-> resolved ___/
```

(`Status::canTransitionTo()` - edges `new↔noticed`, `noticed↔progress`,
`progress↔resolved`, `resolved↔done`, `noticed↔resolved`.)

A transition must satisfy **both** the role's allowed target set and the lifecycle chain
(`Report::allowedStatusTargets()`):

| Actor | Allowed targets | Consequence |
|---|---|---|
| Developer / admin | `noticed`, `progress`, `resolved` | drive the main lifecycle |
| Reporter / owner | `progress`, `done` | **Reopen** (`resolved → progress`) or **Confirm** (`resolved → done`) |

The intersection with `canTransitionTo()` is what actually passes. Examples: a reporter
may move `resolved → done` (Confirm) and `resolved → progress` (Reopen), but not
`new → noticed` or `progress → resolved`; a developer may move `noticed → resolved`
(skipping progress) but not `done → resolved`. Anything else → `403 "Invalid status
transition."`.

Two implicit transitions happen outside the PATCH endpoint: viewing a `new` report as a
developer auto-advances it to `noticed` (GET `report/{report}`), and new reports are
created at `new`.

Status groups for querying (`Status::openValues()` / `closedValues()`): open =
`new, noticed, progress` (group `unresolved`); closed = `resolved, done` (group
`resolved`).

## Errors

All error responses are JSON unless noted. The two envelope shapes:

- **Laravel validation** (`$request->validate()`, grammar violations) -
  `{ "message": "...", "errors": { "<field>": ["..."] } }` with HTTP 422. Grammar
  violations use field keys like `filter.status`, `include.author`, `sort.created_at`,
  `paginate.per_page`, `group_by`.
- **Auth controller** - `{ "errors": [...], "pending": false }`, where `errors` is
  either a flat list of strings or a field-keyed map (register's provider validation);
  `pending: true` marks a 202 "account created but inactive".

| Status | Meaning |
|---|---|
| `200` | success (collections use `{ data, meta }`, see envelopes) |
| `201` | created (report, comment, screenshot, video, user) |
| `202` | account pending - register/login returned `{ "errors": [...], "pending": true }` |
| `204` | deleted (report, comment, user) |
| `401` | unauthenticated: `{ "message": "Invalid or expired token." }` / `{ "message": "Unauthenticated." }` (missing token, bad signature/expiry, or `token_version` mismatch) |
| `403` | forbidden: `"Forbidden."`, `"Admin access required."`, `"Invalid status transition."`, `"Feature disabled."`, `"Registration not allowed."`, `"Forbidden origin."` (guest) |
| `404` | not found, including parent/child mismatch on nested routes and missing media rows |
| `409` | **duplicate media** - `{ "message": "Screenshot already exists for this report." }` / `{ "message": "Video already exists for this report." }` (one media per report) |
| `422` | validation / grammar - never a silent ignore: unknown or non-whitelisted group, target, operator, or attribute; invalid operator for a field; `per_page` outside 1–100; `page` < 1; unknown status/group; two `group_by` columns; last-admin protection on user endpoints |
| `429` | rate limit - `redline-auth`, `redline-bootstrap`, or `redline-guest`; response includes `Retry-After` |

Grammar-specific guarantees (from `ResourceQuery`): violations are collected across the
whole request into one 422; excluded parameter groups 422 rather than being dropped;
`include[target]=a&include[target][]=b` (bare + field list for the same target) is a
422 conflict; nested include field lists must be flat lists of attribute names.

## Related

- [Introduction](01-introduction.md)
- [Installation](02-installation.md)
- [Configuration](03-configuration.md)
- [Storage](04-storage.md)
- [Authentication](05-authentication.md)
- [Guards](06-guards.md)
- [Commands](08-commands.md)
- [Emails](09-emails.md)
- [Widget & dashboard](11-widget-dashboard.md)
