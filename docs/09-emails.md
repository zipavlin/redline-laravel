# Emails

Redline sends email through the **host app's configured default mailer** for five events: notifying developers of a new report, password resets, registration approvals, user invitations, and email verification. Every send funnels through one helper, `Zipavlin\Redline\RedlineServiceProvider::mail()`, which applies two rules:

1. **Kill switch** - `REDLINE_MAIL` (env-only, default `true`). When `false`, no Redline email is ever sent.
2. **Real transport required** - `Zipavlin\Redline\RedlineServiceProvider::mailConfigured()` returns `true` only when the resolved default mailer (`config('mail.default')`) is a real transport - **not** `log`, `array`, or `null`. The dev-friendly mailers don't count: with `MAIL_MAILER=log` Redline treats mail as disabled even though Laravel still logs messages.

| Variable | Default | Purpose |
|---|---|---|
| `REDLINE_MAIL` | `true` | Kill switch for all Redline emails; set to `false` to disable every send |

> Mail is load-bearing for some auth flows: with `DomainAuthProvider` (email verification) or `ApprovalAuthProvider` (admin approval), an account cannot activate until a mail is delivered. Configure a real mailer in staging, or those providers strand users in `pending`.

## The five emails

### New report - `NewReportMail`

- **Trigger**: every successful submission to `POST /redline/api/report` (`Zipavlin\Redline\Http\Controllers\Api\ReportController::store`).
- **Recipients**: every user with role `developer` or `admin`, **except the reporter**.
- **Subject**: `New bug report: {title}`, where `title` is the first 80 characters of the report body (HTML stripped) or `Untitled report`.
- **Body**: reporter name, the title, and an "Open report" button linking to the dashboard with `?redline-report={id}` - a deep link straight to the report.

### Password reset - `PasswordResetMail`

- **Trigger**: `POST /redline/api/auth/forgot-password` (`Zipavlin\Redline\Http\Controllers\Api\AuthController::forgotPassword`).
- **Anti-enumeration**: the endpoint always returns `200 {"ok": true}` whether or not the email exists; the mail is only sent when an account matches.
- **Link**: a temporary signed route (`redline.auth.reset-password`) valid **1 hour**, pointing at the dashboard's `/reset-password` page carrying the signed query. The SPA re-POSTs that query to the reset endpoint, so the signature still validates.
- **Recipient**: the account owner.

> The `PasswordResetMail` docblock mentions "admin-triggered resets", but the only send site in the current code is the self-service forgot-password flow - the admin reset endpoint (`UserController::resetPassword`) sets a new password directly, without mail. This docblock claim is unverified against the code.

### Registration approved - `RegistrationApprovedMail`

- **Trigger**: a pending user's registration is approved - either via `PATCH /redline/api/user/{user}` (status set to `approved`) or `POST /redline/api/user/{user}/approve`. Sent only when the account was pending beforehand.
- **Recipients**: the approved user.
- **Body**: notice that login is now possible, with a button to the dashboard.

### User invitation - `UserInvitationMail`

- **Trigger**: `POST /redline/api/user` (`Zipavlin\Redline\Http\Controllers\Api\UserController::store`) - an admin creating a user **without an initial password**. When `mailConfigured()` is true, `password` becomes optional; omitting it emails a signed set-password link.
- **Recipients**: the new user.
- **Link**: the same signed `redline.auth.reset-password` route, valid **1 hour**, pointing at the dashboard `/reset-password` page.
- **Response**: the create response includes `invitation_sent` and `invitation_error` (`mail_not_configured` | `send_failed`) so the admin UI can surface delivery problems. The user record is created regardless - a failed send never fails the request.
- Without a configured mailer, `password` becomes **required** (the admin must set one).

### Email verification - `VerifyEmailMail`

- **Trigger**: `Zipavlin\Redline\Providers\Auth\DomainAuthProvider::afterRegister()` - registration via the domain-restricted auth provider (`REDLINE_AUTH_DOMAINS`).
- **Recipients**: the newly registered user.
- **Link**: a temporary signed route (`redline.auth.verify`) valid **24 hours**; the signature proves email ownership. The account stays `pending` until the link is clicked, at which point `AuthController::verify` activates it.
- If no mailer is configured the send is a silent no-op, so a registration can be left pending indefinitely - see Overview.

## Best-effort sending

`Zipavlin\Redline\RedlineServiceProvider::mail()` is the single send path for every email above:

- **No-op without mail**: an empty recipient list, or `mailConfigured()` false → returns `false`, nothing sent, no exception.
- **Failures never bubble**: the send is wrapped in `try/catch`; any `Throwable` is passed to `report()` (routed to the host app's logging config) and `false` is returned. A broken SMTP relay cannot take down a report submission or a registration.
- **Synchronous**: `Mail::to(...)->send(...)` runs inline - no queue dependency, so nothing is silently lost when no worker is running. That's fine for the low volume of a staging bug tracker.
- **Return value**: `true` when the message was handed to the transport. Callers that must distinguish "no mailer" from "send failed" check `mailConfigured()` first - as `UserController::sendInvitation()` does.

## Customizing email views

All five emails are Markdown mailables rendering from the `redline::mail.*` namespace: `redline::mail.new-report`, `redline::mail.password-reset`, `redline::mail.registration-approved`, `redline::mail.user-invitation`, and `redline::mail.verify`.

Publish the templates to override them:

```bash
php artisan vendor:publish --tag=redline-mail-views
```

This copies the package's `resources/views/mail/` into the host app at `resources/views/vendor/redline/mail/` - the standard vendor-view override location, which takes precedence over the package's own views. Edit the Blade files there (they use Laravel's `x-mail::*` Markdown mail components). Keep the filenames identical; the mailable in `src/Mail/` binds the variables each template can use (e.g. `$name`, `$title`, `$reportUrl`, `$resetUrl`, `$inviteUrl`, `$dashboardUrl`, `$verifyUrl`, `$reporterName`, `$appUrl`).

Styling comes from Laravel's default Markdown mail theme; to restyle it, publish Laravel's own `laravel-mail` assets (`php artisan vendor:publish --tag=laravel-mail`) as you would in any host app.

## Related

- [Authentication](05-authentication.md) - the auth providers that drive verification, approval, and invitation emails
- [Installation](02-installation.md) - `redline:install` mail setup
- [Configuration](03-configuration.md) - full env-variable reference
- [Commands](08-commands.md) - seeding the first admin with `redline:user`
- [Resources](07-resources.md) - the report and user endpoints that trigger these emails
- [Widget & Dashboard](11-widget-dashboard.md) - the dashboard URLs the emails link to
