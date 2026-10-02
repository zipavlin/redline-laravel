# Authentication

Redline authenticates users with a JSON Web Token (JWT, HS256) that is delivered **exclusively as an HttpOnly cookie** - it is never returned in JSON bodies. All auth behavior - self-registration, PINs, domain gating, approvals, and anonymous guest submissions - is driven by a pluggable auth provider selected with `REDLINE_AUTH_PROVIDER` (default `Zipavlin\Redline\Providers\Auth\OpenAuthProvider`).

This chapter covers the token/CSRF contract, the seven providers, roles & capabilities, pending accounts, guest mode, email verification & password reset, and the security defaults.

## How authentication works

### JWT in an HttpOnly cookie

The token is issued by `Zipavlin\Redline\Http\Controllers\Api\AuthController` using `firebase/php-jwt`:

- **Algorithm**: HS256, secret = `config('app.key')` (a `base64:`-prefixed key is decoded before signing/verifying).
- **Payload claims**: `sub` (user id), `ver` (the user's `token_version`), `role`, `name`, `email`, `remember`, `iat`, `exp`.
- **Delivery**: the JWT is set as the HttpOnly `redline_token` cookie (config `redline.auth.cookie.name`); successful register/login/verify responses return only `{ user: {...} }`. JavaScript can never read the token.
- Both Redline cookies are exempted from Laravel's `EncryptCookies` so the middleware and the widget/dashboard JS can read the raw values.

On protected routes, `Zipavlin\Redline\Http\Middleware\RedlineApiAuth` extracts the token from the `Authorization: Bearer` header (cross-origin widget) or the `redline_token` cookie (same-origin dashboard). Bearer takes precedence; the query string is deliberately **never** accepted (it would leak into logs/Referer). The middleware records `redline_auth_source` (`bearer` or `cookie`) for the CSRF layer.

| Variable | Default | Purpose |
|---|---|---|
| `REDLINE_AUTH_PROVIDER` | `OpenAuthProvider` | Auth provider class (see below) |
| `REDLINE_AUTH_TOKEN` | `3600` | JWT lifetime in seconds for a normal login (1 h) |
| `REDLINE_AUTH_REMEMBER_TOKEN` | `2592000` | JWT lifetime in seconds when "remember me" is chosen (30 d) |
| `REDLINE_COOKIE_SECURE` | `true` | `Secure` flag on `redline_token` / `redline_csrf`; set `false` only for plain-HTTP local development |
| `REDLINE_COOKIE_SAME_SITE` | `lax` | `SameSite` attribute on both cookies |
| `REDLINE_COOKIE_DOMAIN` | *(none)* | `Domain` attribute - set to share the cookie across subdomains |
| `REDLINE_AUTH_THROTTLE` | `10` | `redline-auth` rate limit: attempts per minute per IP |

### token_version invalidation

Every JWT carries the user's `token_version` in the `ver` claim. `Zipavlin\Redline\Http\Middleware\RedlineApiAuth` rejects any token whose `ver` does not match the stored value (401). `Zipavlin\Redline\Models\User::bumpTokenVersion()` advances the counter and is called on **logout** and on every **password change** (self-service reset and admin-triggered reset), instantly invalidating every outstanding JWT for that user.

### CSRF: double-submit contract

Alongside the auth cookie, every token issuance also sets a **non-HttpOnly** `redline_csrf` cookie (config `redline.auth.csrf_cookie`, 32 random characters, same TTL) so the widget/dashboard JS can read it.

`Zipavlin\Redline\Http\Middleware\RedlineCsrf` enforces the contract on **cookie-authenticated** requests whose method mutates state (`POST`/`PATCH`/`DELETE`/`PUT`):

- The `redline_csrf` cookie value must be echoed back in the **`X-Redline-CSRF`** header (config `redline.auth.csrf_header`), compared constant-time with `hash_equals`. Missing/mismatched → **419** `CSRF token mismatch.`
- Defense-in-depth: when an `Origin` header is present, it must equal the request's own scheme+host or appear in `REDLINE_ALLOWED_ORIGINS` (comma-separated, escape hatch for reverse-proxy setups where the browser-visible origin differs from the request's scheme+host). Violation → **403**.

**Bearer-authenticated requests skip CSRF entirely**, as do guest submissions and safe methods (`GET`/`HEAD`/`OPTIONS`).

### Auth endpoints

All paths are prefixed with `config('redline.path', 'redline')`:

| Endpoint | Access | Purpose |
|---|---|---|
| `POST /{path}/api/auth/register` | public, throttled | Create an account per the active provider |
| `POST /{path}/api/auth/login` | public, throttled | Exchange email/password for the JWT cookie |
| `GET /{path}/api/auth/verify` | public, throttled | Verify a signed email link (Domain provider) |
| `POST /{path}/api/auth/forgot-password` | public, throttled | Email a signed reset link (always 200) |
| `POST /{path}/api/auth/reset-password` | public, throttled, `signed` | Set a new password |
| `GET /{path}/api/auth/me` | protected | Return the current user |
| `GET /{path}/api/auth/refresh` | protected | Reissue the JWT, preserving the `remember` flag |
| `DELETE /{path}/api/auth` | protected | Logout: bump `token_version`, expire both cookies |

Login honors a `remember` (or `remember_me`) boolean; the remember TTL only applies to that session's token.

## Auth providers

The provider is bound from `config('redline.auth.provider')` / `REDLINE_AUTH_PROVIDER` and drives registration, validation, role resolution, and (optionally) verification. All providers extend `Zipavlin\Redline\Providers\Auth\AuthProvider`.

| Provider | How users get in | Role selection | Env knobs |
|---|---|---|---|
| `OpenAuthProvider` (default) | Anyone can self-register | Free choice; an `admin` request degrades to `reporter` unless `REDLINE_AUTH_ALLOW_SELF_ADMIN=true` | `REDLINE_AUTH_ALLOW_SELF_ADMIN` |
| `PinAuthProvider` | Anyone can self-register with a PIN | PIN decides: reporter PIN → reporter, developer PIN → developer; role selector hidden | `REDLINE_AUTH_PIN_REPORTER`, `REDLINE_AUTH_PIN_DEVELOPER` |
| `DomainAuthProvider` | Registration limited to allowed email domains | Free choice within the domain | `REDLINE_AUTH_DOMAINS` |
| `ApprovalAuthProvider` | Self-register → pending → admin approves | Admin assigns on approval | - |
| `ClosedAuthProvider` | No self-registration; admin creates users | Admin assigns | - |
| `LaravelAuthProvider` | Host application session; first login provisions a reporter | Fixed `reporter` | - |

## OpenAuthProvider

`Zipavlin\Redline\Providers\Auth\OpenAuthProvider` - open registration for trusted staging environments.

- Anyone can register with name/email/password and freely pick a role in the widget's identity modal.
- The `role` field is validated against `reporter|developer|admin`; `resolveRole()` then applies the self-admin rule (see [Roles & capabilities](#roles--capabilities)).
- No pending state, no email verification, no approval: accounts are `approved` immediately and receive a token in the register response.

## PinAuthProvider

`Zipavlin\Redline\Providers\Auth\PinAuthProvider` - anyone can register, but the PIN decides the role.

- The widget hides the role selector and renders a PIN field (`FieldType::PIN`) instead; the field's length is derived from the longer configured PIN.
- Both PINs are compared **constant-time** (`hash_equals`). Reporter PIN → reporter role, developer PIN → developer role. A role whose PIN is unset cannot be claimed.
- A wrong or missing PIN is a **422** field validation error (`Incorrect PIN.`) - it is *not* a pending state (see [Pending registration & login](#pending-registration--login-202)).
- Recommended for "reporters are open, developers need the secret" setups. Note that the PIN is a shared secret, not per-user: rotate `REDLINE_AUTH_PIN_DEVELOPER` if it leaks.

## DomainAuthProvider

`Zipavlin\Redline\Providers\Auth\DomainAuthProvider` - registration restricted to configured email domains, plus email verification.

- `REDLINE_AUTH_DOMAINS` (comma-separated) is checked by `Zipavlin\Redline\Rules\AllowedDomainRule` during registration validation; addresses outside the domains get a 422.
- `requiresEmailVerification()` is `true`: the account is created `pending` and the register response is **202** with `pending: true`. `afterRegister()` emails a **signed verification link valid for 24 h** (`Zipavlin\Redline\Mail\VerifyEmailMail`).
- `GET /{path}/api/auth/verify` validates the link signature inside the controller (`$request->hasValidSignature()`) so a browser following a bad/expired link gets a friendly HTML page instead of Laravel's 403; API clients get the JSON error envelope. Clicking an already-approved link is treated as success (returns a token).
- Role selection stays free within the domain; the self-admin rule applies.
- If no mailer is configured, the verification email is a silent no-op - pick this provider only when mail is set up (`mailConfigured()` is false for `log`/`array`/`null` mailers).

## ApprovalAuthProvider

`Zipavlin\Redline\Providers\Auth\ApprovalAuthProvider` - self-register, then an admin approves.

- Registration creates the account with role `reporter` and status `pending`; the response is **202** with `pending: true`.
- The admin approves the user and assigns the role from the dashboard (or `POST /{path}/api/user/{user}/approve`).
- Pending users cannot log in (login returns 202 - see below) and cannot perform any API operation until approved.

## ClosedAuthProvider

`Zipavlin\Redline\Providers\Auth\ClosedAuthProvider` - no self-registration at all.

- `canRegister()` returns `false`; `POST /{path}/api/auth/register` answers **403** `Registration not allowed.`
- All users are created by an admin via the dashboard or `php artisan redline:user`; existing users log in normally.

## LaravelAuthProvider

`Zipavlin\Redline\Providers\Auth\LaravelAuthProvider` - the host application session **is** the redline identity. Pair it with [`LaravelGuardProvider`](06-guards.md#laravelguardprovider).

- `canRegister()` is `false` and `register()` throws: `POST /{path}/api/auth/register` answers **403**. `canSelectRole()` is `false`.
- `loginMode()` is `'session'`, surfaced through bootstrap as `capabilities.auth.login_mode`. The widget renders **no** login form in this mode; it calls `POST /{path}/api/auth/login` with an **empty body**.
- `login()` reads the host Laravel user via `Auth::guard()->user()`:
  - no host user → **401** `Not authenticated with the host application.`;
  - host user without an email → **401** `Host user has no email address.` (fail closed - the email is the only join key to a redline identity);
  - a redline user with the host user's email already exists → that user is returned **as-is** (no name sync, no role change);
  - otherwise a redline user is provisioned: `role = reporter` (fixed - never resolved from config), `status = approved`, `password = null`, with the host user's `name` (falling back to the email) and `email` copied across.
- The response is the usual `{ user: {...} }` with the HttpOnly `redline_token` cookie. A second login reuses the same redline user - no duplicate is created.
- Credentials are never validated here, so a malformed/empty body is not a 422: the host session is the only credential.

> **WARNING - trusted email ownership.** The provider trusts the host app's email ownership as proof of identity (the email is the only join key). Anyone who can obtain a host account with a given email inherits the matching redline identity, so do **not** pair `LaravelAuthProvider` with host registration that is open to untrusted users.

## Roles & capabilities

Roles are a string enum (`Zipavlin\Redline\Enums\Role`): `reporter`, `developer`, `admin`, plus the shared `guest` identity used for anonymous guest-mode submissions.

Authorization is centralized in `Zipavlin\Redline\Models\User::can(Operation, ?model)`. Pending users (`status = 'pending'`) can do **nothing**; guests can only create reports and only when guest mode is on.

| Action | Reporter | Developer | Admin |
|---|---|---|---|
| Submit reports / comments | ✅ | ✅ | ✅ |
| Edit own report/comment body | ✅ | ✅ | ✅ |
| Change report status | limited¹ | ✅ | ✅ |
| Move pins / upload media | own only | ✅ | ✅ |
| Delete reports / comments | own only | own only | ✅ |
| Manage users | ❌ | ❌ | ✅ |

¹ A report owner may only **Reopen** (resolved → in progress) or **Confirm** (resolved → done); developers/admins drive the main lifecycle transitions.

Body edits (`EDIT`) are **author-only** - even developers/admins cannot rewrite someone else's report/comment body. Deletion (`DELETE`) is owner *or* admin. Status changes, pin moves and media attachment (`UPDATE`) are developer+ or the report owner.

### Seeding the first admin

- With the default `REDLINE_AUTH_ALLOW_SELF_ADMIN=false`, public registration can **never** create an admin: a submitted `admin` role is silently downgraded to `reporter`. Seed the first admin with:

  ```bash
  php artisan redline:user
  ```

- With `REDLINE_AUTH_ALLOW_SELF_ADMIN=true`, the first registrant who claims `developer` or `admin` is **auto-promoted to admin** while no admin account exists (verified in `AuthProvider::resolveRole()` - this also applies to `PinAuthProvider`'s developer PIN path). Enable it only on trusted networks; the auto-promotion is *not* active by default.

## Pending registration & login (202)

Some providers admit users in two steps. Pending responses are signaled by **HTTP 202** with the envelope:

```json
{ "errors": ["Your registration was created and needs to be approved by admin user."], "pending": true }
```

`errors` is a flat list of strings for pending/login failures (a field-keyed map is used only for 422 validation errors such as a wrong PIN).

**When 202 happens:**

| Flow | Provider(s) | Notes |
|---|---|---|
| Register | `DomainAuthProvider` | Pending until the emailed link is clicked |
| Register | `ApprovalAuthProvider` | Pending until an admin approves |
| Login | any provider | When the account's `status` is still `pending` (`Account not yet approved.`) |

**Client contract:** branch on the status code. A 202 must never be treated as a successful session - do not store credentials, do not navigate into the dashboard, and show the backend message as informational text. Only 2xx-with-user responses (register, login, verify, refresh) carry the token cookie.

## Guest mode

`REDLINE_AUTH_GUEST=true` opens anonymous report submission so testers can file bugs without an account.

- Anonymous `POST /{path}/api/report` requests are attributed to a shared user record (`guest@redline.local`, name `Guest`, role `guest`, created on demand) so downstream code always has `$request->user()`. Guests may **only** create reports - every other capability is denied.
- **Origin check**: when `REDLINE_AUTH_GUEST_ORIGINS` (comma-separated) is set, the request's `Origin` (or `Referer` fallback) must match the allowlist or the request's own scheme+host, else **403**. Unset → no restriction (backward compatible).
- **Throttle**: anonymous submissions are rate-limited by the `redline-guest` limiter, `REDLINE_AUTH_GUEST_THROTTLE` default **20/min/IP**; over the limit → **429**.
- Guest submissions are exempt from CSRF (they carry no cookie auth).

> **Custom path caveat (verified in `Zipavlin\Redline\Http\Middleware\RedlineApiAuth`):** the guest exemption matches the *literal* path `redline/api/report` (`$request->is('redline/api/report')`). If you set a custom `REDLINE_PATH`, the guest bypass will **not** match and anonymous submissions will get 401 - cookie/Bearer-authenticated requests are unaffected. `REDLINE_ALLOWED_ORIGINS` is a separate escape hatch for the CSRF Origin check on cookie-mutating requests, not a substitute for the guest path issue.

## Email verification & password reset

**Verification (Domain provider).** Registration emails a `URL::temporarySignedRoute` link valid **24 h**, carrying the user's email. The signature (HMAC over `config('app.key')`) is what proves email ownership; `verify()` flips the account to `approved` and returns a token.

**Forgot password.** `POST /{path}/api/auth/forgot-password` **always returns 200** (`{ "ok": true }`) whether or not the email exists - anti-enumeration. When the account exists, it emails a **signed reset link valid 1 h** (`PasswordResetMail`) that points at the dashboard's reset page; the SPA re-POSTs the same signed query to the API.

**Reset.** `POST /{path}/api/auth/reset-password` is protected by Laravel's `signed` middleware; it validates the new password (min 8 chars), saves it, and calls `bumpTokenVersion()` so all previously issued JWTs are rejected. Invalid/expired links → 422.

**Login anti-enumeration.** The base login (`AuthProvider::login()`) validates `email` (required, valid address, max 255) and `password` (required, string) and returns a **422** with a readable message for malformed input before any lookup. Past that point it returns the same `Invalid credentials.` 401 for a nonexistent email and a wrong password, and runs a dummy hash check on the not-found path to keep timing comparable. (Session-based providers such as `LaravelAuthProvider` bypass this validation entirely - they have no credentials to check.)

## Security notes

- **The JWT never appears in JSON** - only as the HttpOnly `redline_token` cookie; JS cannot read it. The non-HttpOnly `redline_csrf` cookie is the only JS-readable secret.
- **Brute-force throttles**: `redline-auth` (10/min/IP) covers register, login, verify, forgot-password and reset-password; `redline-guest` (20/min/IP) covers anonymous reports; `redline-bootstrap` (120/min/IP) covers the public bootstrap endpoint.
- **Password minimums**: 8 characters for self-registration and all reset flows; the `php artisan redline:user` command accepts 6 - an intentional lower bar for admin-provisioned accounts, but consider matching the 8-char rule operationally.
- **Self-admin is locked down by default**: `REDLINE_AUTH_ALLOW_SELF_ADMIN=false` - public registration cannot mint an admin; first admin via `redline:user`.
- **Cookies**: `Secure` by default (`REDLINE_COOKIE_SECURE=false` only for local HTTP dev), `SameSite=lax`, optional `Domain` for subdomains.
- PINs and passwords are compared/hashed with constant-time primitives (`hash_equals`, Laravel `Hash`).

## Custom Authentication Provider

- Extend `Zipavlin\Redline\Providers\Auth\AuthProvider` and register it in `config.redline.auth.provider` or `REDLINE_AUTH_PROVIDER`.

## Related

- [Configuration](03-configuration.md) - full env-var reference and `config/redline.php` structure
- [Guards](06-guards.md) - when Redline is active (environment/debug guards, `REDLINE_GUARD_EXCLUDE`)
- [Commands](08-commands.md) - `redline:install`, `redline:update`, `redline:user`, `redline:remove`
- [Emails](09-emails.md) - verification, password-reset and invitation mail templates
- [Widget & dashboard](11-widget-dashboard.md) - the identity modal, capabilities from `/api/bootstrap`, and client auth flow
