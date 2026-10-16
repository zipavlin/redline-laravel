<?php

namespace Zipavlin\Redline\Http\Controllers\Api;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Zipavlin\Redline\Actions\ReportActions;
use Zipavlin\Redline\Enums\Status;
use Zipavlin\Redline\Http\Query\QuerySchema;
use Zipavlin\Redline\Http\Query\ResourceQuery;
use Zipavlin\Redline\Models\Report;

/**
 * @see QuerySchema   the query grammar wire format (filter[]/include[]/sort[]/
 *                    paginate[]/aggregate[]) - the full reference lives there,
 *                    since the grammar is resource-agnostic, not report-specific.
 * @see ResourceQuery parsing + validation + apply (entry point: Report::resourceQuery())
 */
class ReportController extends Controller
{
    public function __construct(private readonly ReportActions $actions) {}

    /**
     * GET /redline/api/report
     */
    public function index(Request $request): JsonResponse
    {
        return response()->json($this->actions->list($request));
    }

    /**
     * GET /redline/api/report/aggregate
     */
    public function aggregate(Request $request): JsonResponse
    {
        return response()->json($this->actions->aggregate($request));
    }

    /**
     * GET /redline/api/report/{report}
     */
    public function show(Request $request, Report $report): JsonResponse
    {
        // HTTP's new -> noticed auto-acknowledge, through the same write path
        // the MCP report_get mark_noticed input uses - no duplicated semantics.
        // The acknowledge is the developer caller's side effect only: every
        // other caller gets a plain read.
        $user = $this->authUser($request);

        // Validate the READ first - the VIEW gate and the include[] grammar run
        // before the acknowledge below, so a request that is refused or 422s can
        // never leave the status write behind.
        $query = $this->actions->includeQuery($request, $user);

        if ($report->status === Status::NEW->value && $user->isDeveloper) {
            $report = $this->actions->updateStatus($report, $user, Status::NOTICED->value);
        }

        // Hydrated from the fresh post-write state, through the query that was
        // already validated above.
        return response()->json($query->hydrate($report));
    }

    /**
     * POST /redline/api/report
     *
     * Submit a new report. Guest mode → attribute to shared Guest user.
     */
    public function store(Request $request): JsonResponse
    {
        $report = $this->actions->create($this->authUser($request), $request->all());

        return response()->json($report->toArray(), 201);
    }

    /**
     * PATCH /redline/api/report/{report}
     *
     * Update body text only.
     */
    public function updateBody(Request $request, Report $report): JsonResponse
    {
        $report = $this->actions->updateBody($report, $this->authUser($request), $request->all());

        return response()->json($report->toArray());
    }

    /**
     * POST /redline/api/report/{report}/move
     */
    public function updatePin(Request $request, Report $report): JsonResponse
    {
        $report = $this->actions->updatePin($report, $this->authUser($request), $request);

        return response()->json($report->toArray());
    }

    /**
     * PATCH /redline/api/report/{report}/status
     */
    public function updateStatus(Request $request, Report $report): JsonResponse
    {
        $fresh = $this->actions->updateStatus($report, $this->authUser($request), $request->input('status'));

        return response()->json($fresh->toArray() + [
            'members' => $fresh->memberRows($fresh->members()->get()),
        ]);
    }

    /**
     * PATCH /redline/api/report/{report}/priority
     */
    public function updatePriority(Request $request, Report $report): JsonResponse
    {
        $report = $this->actions->updatePriority($report, $this->authUser($request), $request);

        return response()->json($report->toArray());
    }

    /**
     * PATCH /redline/api/report/{report}/due_at
     */
    public function updateDueAt(Request $request, Report $report): JsonResponse
    {
        $report = $this->actions->updateDueAt($report, $this->authUser($request), $request);

        return response()->json($report->toArray());
    }

    /**
     * DELETE /redline/api/report/{report}
     */
    public function destroy(Request $request, Report $report): Response
    {
        $this->actions->delete($report, $this->authUser($request));

        return response()->noContent();
    }
}
