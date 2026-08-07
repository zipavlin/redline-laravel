<?php

use Zipavlin\Redline\Enums\Feature;
use Zipavlin\Redline\Providers\Auth\OpenAuthProvider;
use Zipavlin\Redline\Providers\Guard\EnvironmentGuardProvider;
use Zipavlin\Redline\Providers\Storage\DatabaseStorageProvider;

return [
    // -------------------------------------------------------------------------
    // Path
    // -------------------------------------------------------------------------
    // URL prefix for all Redline routes (widget API + dashboard).
    // Default: 'redline'  →  /redline/api/...  and  /redline
    'path' => env('REDLINE_PATH', 'redline'),

    // -------------------------------------------------------------------------
    // Cloud
    // -------------------------------------------------------------------------
    // Remote Redline API URL used by CloudStorageProvider. The widget points at
    // this URL; no local models/routes/files are used.
    'cloud_url' => env('REDLINE_CLOUD_URL', null),

    // -------------------------------------------------------------------------
    // Storage
    // -------------------------------------------------------------------------
    'storage' => [
        // Provider
        // Which storage provider to use. Default: DatabaseStorageProvider (always - set
        // REDLINE_STORAGE_PROVIDER explicitly to switch; there is no automatic fallback).
        // Options:
        //   DatabaseStorageProvider  - Eloquent + own SQLite (default; requires pdo_sqlite)
        //   FileStorageProvider      - JSON files via orbit (requires Laravel 12+ and ryangjchandler/orbit)
        //   CloudStorageProvider     - widget points to a remote Redline API; no local storage
        'provider' => env('REDLINE_STORAGE_PROVIDER', DatabaseStorageProvider::class),

        // Path
        // Where to store files. Default: 'redline'
        'path' => [
            'base' => env('REDLINE_STORAGE_PATH_BASE', 'redline'),
            'screenshots' => env('REDLINE_STORAGE_PATH_SCREENSHOTS', 'screenshots'),
            'videos' => env('REDLINE_STORAGE_PATH_VIDEOS', 'videos'),
            'database' => env('REDLINE_STORAGE_PATH_DATABASE', 'database'),
        ],
    ],

    // -------------------------------------------------------------------------
    // Guard
    // -------------------------------------------------------------------------
    'guard' => [
        // Provider
        // Controls when Redline is active.
        // Default: APP_ENV=staging AND APP_DEBUG=true
        'provider' => env('REDLINE_GUARD_PROVIDER', EnvironmentGuardProvider::class),
        'exclude' => env('REDLINE_GUARD_EXCLUDE', null),
    ],

    // -------------------------------------------------------------------------
    // Auth
    // -------------------------------------------------------------------------
    'auth' => [
        // Provider
        // Which auth provider to use. Default: OpenAuthProvider
        // Options:
        //   OpenAuthProvider      - anyone can register; role freely selectable (default)
        //   PinAuthProvider       - PIN determines role; no role selector shown
        //   DomainAuthProvider    - registration restricted to allowed domain(s) + email verification
        //   ApprovalAuthProvider  - self-register → pending → admin approves
        //   ClosedAuthProvider    - no self-registration; admin creates users only
        //   CloudAuthProvider     - cloud manages all user auth
        'provider' => env('REDLINE_AUTH_PROVIDER', OpenAuthProvider::class),

        // JWT token TTL
        // How long (in seconds) a JWT token is valid. Default: 3600 (1 hour).
        'token' => env('REDLINE_AUTH_TOKEN', 3600),

        // JWT token TTL when "Remember me" is selected at login.
        // Default: 2592000 (30 days).
        'remember_token' => env('REDLINE_AUTH_REMEMBER_TOKEN', 2592000),

        // Guest mode
        // When true, POST /redline/api/report is open to unauthenticated callers.
        // Anonymous submissions are attributed to a shared "Guest" user record.
        'guest' => env('REDLINE_AUTH_GUEST', false),

        // Allow self-admin
        // Security default (false): public registration can NEVER create an admin -
        // seed the first admin via `php artisan redline:user`. Set true only on
        // trusted internal networks to let the first developer/admin bootstrap the
        // admin account automatically.
        'allow_self_admin' => env('REDLINE_AUTH_ALLOW_SELF_ADMIN', false),

        // PIN
        // When using PinAuthProvider, specify a PIN per role.
        // reporter PIN → reporter role; developer PIN → developer role.
        'pin' => [
            'reporter' => env('REDLINE_AUTH_PIN_REPORTER', null),
            'developer' => env('REDLINE_AUTH_PIN_DEVELOPER', null),
        ],

        // Domain
        // When using DomainAuthProvider, specify allowed domain(s) - separated by comma.
        'domains' => env('REDLINE_AUTH_DOMAINS', null),

        // Auth cookie
        // The redline_token JWT is delivered exclusively as an HttpOnly cookie for
        // same-origin browser auth; it is never returned in API JSON bodies.
        'cookie' => [
            'name' => 'redline_token',
            'secure' => env('REDLINE_COOKIE_SECURE', true),
            'same_site' => env('REDLINE_COOKIE_SAME_SITE', 'lax'),
            'domain' => env('REDLINE_COOKIE_DOMAIN', null),
            'path' => '/',
        ],

        // Package-owned double-submit CSRF
        // A non-HttpOnly `redline_csrf` cookie is set alongside the auth cookie;
        // state-changing cookie-authenticated requests must echo it back in the
        // X-Redline-CSRF header. Bearer-authenticated requests skip CSRF.
        'csrf_cookie' => 'redline_csrf',
        'csrf_header' => 'X-Redline-CSRF',

        // Guest mode hardening
        // guest_origins: comma-separated widget origins allowed to submit guest
        // reports. When set, POST /redline/api/report without a token must present
        // a matching Origin (or Referer) header or the request's own scheme+host.
        'guest_origins' => env('REDLINE_AUTH_GUEST_ORIGINS', null),

        // Per-minute per-IP rate limit for guest report submissions.
        'guest_throttle' => env('REDLINE_AUTH_GUEST_THROTTLE', 20),

        // Extra origins accepted by the cookie-mutation Origin check (escape hatch
        // for reverse-proxy setups where the request's scheme+host differs from
        // the browser-visible origin). Comma-separated.
        'allowed_origins' => env('REDLINE_ALLOWED_ORIGINS', null),
    ],

    // -------------------------------------------------------------------------
    // Features
    // -------------------------------------------------------------------------
    // Comma-separated list of features to disable. All features are on by default.
    // Example: REDLINE_DISABLED=videos,files
    // Available: screenshots, videos, files, comments, mentions
    'disabled' => array_values(array_filter(
        array_map(
            fn ($f) => Feature::tryFrom(trim($f)),
            explode(',', env('REDLINE_DISABLED', ''))
        )
    )),
];
