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

## Behind HTTP Basic Auth (Apache)

On a host behind server-side HTTP Basic Auth, the MCP surface needs one extra
step the rest of Redline does not:

- Browser (cookie) auth is unaffected; only Bearer consumers lose out.
- Apache consumes the `Authorization: Basic ...` layer itself and forwards a
  `Bearer` header only after the Basic check has passed - an `rlm_` header
  arriving before that never reaches Redline's middleware chain.

- An unexempted MCP client therefore keeps answering Apache's 401 Basic
  challenge and can never authenticate.

Exempt the MCP endpoint inside the vhost that actually serves the site (verify
which one with `apachectl -S`; placement errors are silent), after the Basic
Auth configuration:

```apache
<LocationMatch "^/{redline.path}/api/mcp">
    Require all granted
</LocationMatch>
```

(On a host with a custom `REDLINE_PATH`, put the literal path into the
pattern.)

nginx equivalent - turn Basic Auth off for that location. The block below is
the minimal diff off your edge config; copy your site's normal PHP location
block (fastcgi_pass etc.) around it:

```nginx
location ~ ^/{redline.path}/api/mcp {
    auth_basic off;  # copy your site's normal PHP location block (fastcgi_pass etc.)
}
```

That leaves the endpoint safe on Redline layers alone: the route stays behind
Redline's middleware chain - the `mcp` feature flag, the bearer token check and
the two limiters - so it answers only to holders of a valid `rlm_` token.

## Related

- [Configuration](03-configuration.md) - `REDLINE_FEATURES` (`mcp`) and the throttle knobs
- [Commands](08-commands.md) - `redline:mcp` token lifecycle, `redline:enable mcp`, `redline:update` feature state
- [Middleware](10-middleware.md) - the group middleware the MCP route rides on
