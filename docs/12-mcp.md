# MCP Server

Redline ships an MCP (Model Context Protocol) server so coding agents can work
directly against the report store: read the triage list, open a full report
with its screenshot, file reports, advance statuses and page comments.

## The feature

The server exists only when `mcp` is enabled in `REDLINE_FEATURES`; nothing mounts
otherwise, on any host, in any environment. `php artisan redline:enable mcp`
flips it on after install.

- Endpoint: `POST /{REDLINE_PATH}/api/mcp` - a streamable-HTTP transport served
  by laravel/mcp.
- Middleware chain, in order: `RedlineActive` -> `RedlineMcpEnabled` ->
  `throttle:redline-mcp-ip` -> `RedlineApiAuth` -> `throttle:redline-mcp`
  (see [Middleware](10-middleware.md)).
- Eight tools. Every read and write runs through the same `src/Actions/*`
  layer the HTTP controllers use, so gates, validation and error shapes cannot
  drift between the agents and the dashboard:

| Tool | What it does |
|---|---|
| `report_list` | compact triage list over the same query grammar the HTTP collection runs (body excluded) |
| `report_get` | one full read in the HTTP report shape; the HTTP auto-acknowledge of new reports is suppressed unless `mark_noticed` opts in |
| `report_create` | file a report through the shared store rules (a pin needs both coordinates; `location` is derived from `url`, never agent-supplied) |
| `report_update_status` | move a report along its status lifecycle; a rejection answers with the caller role's allowed targets |
| `comment_list` | a report's comments, oldest first, in the contract's pagination envelope |
| `comment_create` | append a comment through the shared Actions layer |
| `screenshot_get` | the report's screenshot inline when under ~2 MB, otherwise metadata plus the authenticated binary URL |
| `user_list` | the compact roster agents need to resolve people (roles and initials) |

## Authentication

Bearer `rlm_` tokens only, sent as `Authorization: Bearer rlm_...` on every
request. That is the accepted shape and nothing else: an `rlm_` value found in
a cookie is rejected outright, and query strings are never accepted for any
token. The token lifecycle (creation, roster, revocation, hints) is the
`php artisan redline:mcp` command's job - see
[Commands](08-commands.md#redline-mcp); this chapter does not duplicate it.

An `rlm_` bearer authenticates the whole REST surface, not only the MCP route,
so an agent can also attach media through the HTTP upload endpoints.

## Agent setup

The `redline:mcp` wizard prints copy-paste setup blocks for Claude Code,
OpenCode, Codex, PI, Cursor, VS Code and Gemini CLI (endpoint and token
inlined) - see [Commands](08-commands.md#redline-mcp); this chapter does not
duplicate them.

## Throttling

Two limiters bracket the endpoint - `redline-mcp-ip` (240/min/IP, pre-auth
flood control) and `redline-mcp` (120/min, post-auth) - with their env knobs
exactly as documented in [Configuration](03-configuration.md#rate-limiting);
one cross-reference, no duplication here.

## Behind HTTP Basic Auth

On a host behind server-side HTTP Basic Auth, the MCP surface needs one extra
step the rest of Redline does not:

- Browser (cookie) auth is unaffected; only Bearer consumers lose out.
- Apache consumes the `Authorization: Basic ...` layer itself and forwards a
  `Bearer` header only after the Basic check has passed - an `rlm_` header
  arriving before that never reaches Redline's middleware chain.
- An unexempted MCP client therefore keeps answering the Basic challenge and
  can never authenticate.

The endpoint has to be exempted from the challenge in the vhost that actually
serves the site (verify which one with `apachectl -S`; placement errors are
silent). `php artisan redline:mcp` prints the same snippet for the configured
path, with the path regex-quoted so characters such as `+`, `(` or `.` stay
literal. The examples keep the `{redline.path}` placeholder - substitute the
literal path (quote regex characters if it has any) when `REDLINE_PATH` is
customised.

### Apache 2.4+

Both Apache variants below need Apache **2.4.8+** - the `<If>` directive. Do not
mix `Require` with `Order` / `Allow` / `Satisfy` in one scope: Apache warns and
merges the results.

```apache
# Let the Redline MCP endpoint through the site's HTTP Basic Auth.
# Add this inside the site's <VirtualHost> block, in both the :80 and :443 vhosts.
# (Certbot's *-le-ssl.conf is a copy made at certificate time, so edits to :80 are not carried over.)
# It matches the original request line, so it still applies after Laravel's .htaccess
# rewrites the request internally to index.php.
<If "%{THE_REQUEST} =~ m#^[A-Z]+ /{redline.path}/api/mcp(/| )#">
    Require all granted
</If>
```

### Legacy Apache

Use this version if the config still uses the legacy `Order` / `Allow` /
`Satisfy` directives (for example in a `<Directory>` block that also has
`Require user ...`). The `Satisfy Any` + `Allow from all` pair already grants
the request, so it needs no `Require all granted` line. If requests still fail,
look for `AH01797 ... index.php` in the Apache error log: the access rules are
being applied to the internally rewritten `/index.php` request.

```apache
# Let the Redline MCP endpoint through the site's HTTP Basic Auth.
# Use this version if your config still uses the legacy Order / Allow / Satisfy directives
# (for example in a <Directory> block that also has Require user ...).
# Add it inside the site's <VirtualHost> block, in both the :80 and :443 vhosts.
# If requests still fail, look for "AH01797 ... index.php" in the Apache error log. It means
# the access rules are being applied to the internally rewritten /index.php request.
<If "%{THE_REQUEST} =~ m#^[A-Z]+ /{redline.path}/api/mcp(/| )#">
    AuthType None
    Satisfy Any
    Order allow,deny
    Allow from all
</If>
```

### nginx (untested)

Not tested against a real host yet. Copy the site's own PHP location block,
change only the `location` regex and add `auth_basic off;`; the other lines in
the example below are fallbacks. Regex locations match in config order, so put
it next to the site's other regex locations (for example after
`location ~ \.php`).

```nginx
# Let the Redline MCP endpoint through the site's HTTP Basic Auth.
# Copy your site's normal PHP location block, change only the location regex
# and add auth_basic off; the other lines below are fallback examples.
# Regex locations match in config order - put this next to your other regex
# locations, for example after "location ~ \.php".
location ~ ^/{redline.path}/api/mcp(/|$) {
    auth_basic off;

    include fastcgi_params;
    fastcgi_param SCRIPT_FILENAME $realpath_root/index.php;
    fastcgi_param SCRIPT_NAME /index.php;
    fastcgi_pass unix:/run/php/php8.3-fpm.sock;
}
```

### Check

```bash
# Check: curl -i https://<site>/{redline.path}/api/mcp
# A JSON 401 with "WWW-Authenticate: Bearer" means the Basic Auth is bypassed and the app wants a token.
# A "Restricted Files" 401 page from Apache, or a Basic 401 page from nginx, means the block is not taking effect.
# Check that the rest of the site is still protected: curl -i https://<site>/ must still answer the HTTP Basic challenge.
```

Keep the bypass inside the `<If>` / `location` block - never paste `AuthType
None`, `Satisfy Any`, `Allow from all` or `auth_basic off` into a `<Directory>`
or a wider location.

That leaves the endpoint safe on Redline layers alone: the route stays behind
Redline's middleware chain - the `mcp` feature flag, the bearer token check and
the two limiters - so it answers only to holders of a valid `rlm_` token.

## Sample agent prompt

A short opening prompt that keeps the agent's work reviewable:

> Use redline mcp and make a short list of reports on all pages. We need to fix
> them one by one with a short plan beforehand (that I approve) so we will not
> be fixing wrong thing

## Related

- [Configuration](03-configuration.md) - `REDLINE_FEATURES` (`mcp`) and the throttle knobs
- [Commands](08-commands.md) - `redline:mcp` token lifecycle, `redline:enable mcp`, `redline:update` feature state
- [Middleware](10-middleware.md) - the group middleware the MCP route rides on
