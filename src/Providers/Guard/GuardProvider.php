<?php

namespace Zipavlin\Redline\Providers\Guard;

use Illuminate\Http\Request;

/**
 * Controls when the Redline package is active.
 *
 * Override isActive() to use any condition: a feature flag, a request header,
 * an IP allowlist, etc.
 *
 * Bind your implementation via REDLINE_GUARD_PROVIDER in .env or by
 * overriding the 'guard' key in config/redline.php.
 */
abstract class GuardProvider
{
    /**
     * Return true if Redline should be active for the current request / process.
     * When false, routes, middleware, and widget injection are skipped entirely.
     */
    abstract public function isActive(Request $request): bool;

    final public function shouldInject(Request $request): bool
    {
        return $this->isActive($request) && ! $this->isExcluded($request);
    }

    protected function isExcluded(Request $request): bool
    {
        $requestPath = trim($request->path(), '/');

        return str(config('redline.guard.exclude'))
            ->explode(',')
            ->map(fn ($pattern) => trim(trim($pattern), '/'))
            ->filter()
            ->contains(function ($pattern) use ($requestPath) {
                // Trailing /* — subpath only, parent NOT excluded
                if (str_ends_with($pattern, '/*')) {
                    $prefix = rtrim(substr($pattern, 0, -2), '/');

                    return str_starts_with($requestPath, $prefix.'/');
                }

                // Other wildcard — fnmatch glob
                if (str_contains($pattern, '*')) {
                    return fnmatch($pattern, $requestPath);
                }

                // No wildcard — exact match OR any subpath
                return $requestPath === $pattern
                    || str_starts_with($requestPath, $pattern.'/');
            });
    }
}
