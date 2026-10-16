<?php

namespace Zipavlin\Redline\Http\Controllers\Api;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Zipavlin\Redline\Actions\MemberActions;
use Zipavlin\Redline\Models\Report;

/**
 * Report members - the write side of the report-user pivot.
 *
 * Reads (include[members], filter[member]) live on Report::querySchema();
 * these two endpoints mutate pairs and always answer with the report's full,
 * name-sorted member list so the client never has to refetch or reconcile.
 */
class MemberController extends Controller
{
    public function __construct(private readonly MemberActions $actions) {}

    /**
     * POST /redline/api/report/{report}/member
     */
    public function store(Request $request, Report $report): JsonResponse
    {
        return response()->json(['members' => $this->actions->store($this->authUser($request), $request, $report)]);
    }

    /**
     * DELETE /redline/api/report/{report}/member
     */
    public function destroy(Request $request, Report $report): JsonResponse
    {
        return response()->json(['members' => $this->actions->destroy($this->authUser($request), $request, $report)]);
    }
}
