<?php

namespace Zipavlin\Redline\Mcp\Concerns;

use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Zipavlin\Redline\Models\User;

/**
 * Synthetic HTTP requests for the MCP read tools.
 *
 * The tools drive `ResourceQuery` - which reads `$request->user()` for the
 * `filter[mine]` resolver and for developer-gated includes (`browser`). A
 * synthetic request MUST therefore carry the authenticated caller's user
 * resolver, every time: without it `filter[mine]` silently returns zero rows
 * and developer gates degrade to reporter payloads - the exact silent-bug
 * shape this concern exists to make impossible.
 */
trait CanBuildQueryRequests
{
    /** Hard cap on `per_page` for every MCP collection tool (MCP contract). */
    protected const MCP_MAX_PER_PAGE = 50;

    /**
     * `per_page` used when the caller sends none. The MCP contract's
     * collection envelope always carries per_page/current_page/last_page, so
     * the collection tools never page without bounds.
     */
    protected const MCP_DEFAULT_PER_PAGE = 50;

    /**
     * Build the query request for one grammar-driven tool call.
     *
     * @param  array<string, mixed>  $query  the shape HTTP requests carry:
     *                                       filter[], include[], sort[], paginate[]
     */
    protected function queryRequest(array $query, ?User $user): Request
    {
        $request = Request::create('/redline/api/report', 'GET', $query);
        $request->setUserResolver(fn (): ?User => $user);

        return $request;
    }

    /**
     * The caller's per_page/page inputs as the grammar's paginate[] pair.
     * A numeric per_page is clamped to the MCP cap; anything non-numeric is
     * passed through untouched, so the grammar surfaces its own actionable
     * validation error instead of a silently substituted default.
     *
     * Only for tools whose pagination runs through the grammar - see
     * paginationBounds() for the direct-paginator tools.
     *
     * @param  array<string, mixed>  $arguments
     * @return array<string, mixed>
     */
    protected function paginateQuery(array $arguments): array
    {
        $perPage = $arguments['per_page'] ?? null;

        $paginate = [
            'per_page' => is_numeric($perPage)
                ? min((int) $perPage, self::MCP_MAX_PER_PAGE)
                : ($perPage ?? self::MCP_DEFAULT_PER_PAGE),
        ];

        if (array_key_exists('page', $arguments)) {
            $paginate['page'] = $arguments['page'];
        }

        return $paginate;
    }

    /**
     * Validate + bound the per_page/page pair for tools that page Eloquent
     * directly (report_list goes through the grammar's own paginate[]
     * validation instead). Rules mirror the grammar: per_page within 1..cap,
     * page 1 or greater, and an omitted per_page is the MCP default - never
     * unbounded.
     *
     * @param  array<string, mixed>  $arguments
     * @return array{0: int, 1: int} [perPage, page]
     */
    protected function paginationBounds(array $arguments): array
    {
        $perPage = $arguments['per_page'] ?? null;
        $page = $arguments['page'] ?? null;

        if ($perPage !== null && ! is_numeric($perPage)) {
            throw ValidationException::withMessages([
                'per_page' => ['per_page must be an integer between 1 and '.self::MCP_MAX_PER_PAGE.'.'],
            ]);
        }

        if ($page !== null && ! is_numeric($page)) {
            throw ValidationException::withMessages([
                'page' => ['page must be an integer of 1 or greater.'],
            ]);
        }

        $boundedPerPage = $perPage === null
            ? self::MCP_DEFAULT_PER_PAGE
            : min((int) $perPage, self::MCP_MAX_PER_PAGE);

        if ($boundedPerPage < 1) {
            throw ValidationException::withMessages([
                'per_page' => ['per_page must be an integer between 1 and '.self::MCP_MAX_PER_PAGE.'.'],
            ]);
        }

        $boundedPage = $page === null ? 1 : (int) $page;

        if ($boundedPage < 1) {
            throw ValidationException::withMessages([
                'page' => ['page must be an integer of 1 or greater.'],
            ]);
        }

        return [$boundedPerPage, $boundedPage];
    }
}
