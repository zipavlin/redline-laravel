<?php

namespace Zipavlin\Redline\Http\Controllers\Api;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Zipavlin\Redline\Enums\Operation;
use Zipavlin\Redline\Enums\Role;
use Zipavlin\Redline\Models\Base\Assignment;
use Zipavlin\Redline\Models\Base\Report;
use Zipavlin\Redline\Models\Base\User;
use Zipavlin\Redline\Support\AssigneeRow;

/**
 * Report assignees - the write side of the assignment junction.
 *
 * Reads (include[assignees], filter[assignee], filter[assigned]) live on
 * Base\Report::querySchema(); these two endpoints mutate pairs and always
 * answer with the report's full, name-sorted assignee list so the client
 * never has to refetch or reconcile.
 */
class AssignmentController extends Controller
{
    /**
     * POST /redline/api/report/{report}/assignee
     *
     * Additive and idempotent: pairs that already exist are left alone, so
     * re-posting the same list is a no-op rather than a 409 or a duplicate row.
     */
    public function store(Request $request, Report $report): JsonResponse
    {
        $this->checkUser($request, Operation::UPDATE, $report);

        $userIds = $this->validatedUserIds($request);
        $this->assertUsersExist($userIds);

        $assignmentRows = array_map(fn (string $userId): array => [
            'id' => (string) Str::uuid7(),
            'report_id' => $report->id,
            'user_id' => $userId,
            'created_at' => now(),
            'updated_at' => now(),
        ], $userIds);

        // The unique(report_id, user_id) index makes this additive and
        // idempotent in one statement: existing pairs are ignored, and a
        // concurrent POST that wins the race is ignored the same way.
        app(Assignment::class)->insertOrIgnore($assignmentRows);

        return response()->json(['assignees' => $this->assigneeRows($report)]);
    }

    /**
     * DELETE /redline/api/report/{report}/assignee
     *
     * Removing a pair that does not exist is a no-op (idempotent), matching store().
     */
    public function destroy(Request $request, Report $report): JsonResponse
    {
        $this->checkUser($request, Operation::UPDATE, $report);

        $userIds = $this->validatedUserIds($request);

        app(Assignment::class)
            ->where('report_id', $report->id)
            ->whereIn('user_id', $userIds)
            ->delete();

        return response()->json(['assignees' => $this->assigneeRows($report)]);
    }

    /**
     * Validated, de-duplicated `user_ids` payload (required, non-empty string list).
     *
     * @return array<int, string>
     */
    private function validatedUserIds(Request $request): array
    {
        $validated = $request->validate([
            'user_ids' => ['required', 'array', 'min:1', 'max:100'],
            'user_ids.*' => ['string', 'max:255'],
        ]);

        return array_values(array_unique($validated['user_ids']));
    }

    /**
     * Reject the whole request when any id does not resolve to an assignable
     * user, rather than silently skipping it - a partial attach would hide a
     * client bug. Pending accounts are not assignable (they cannot log in), and
     * neither are guests (they exist only to file anonymous reports).
     *
     * @param  array<int, string>  $userIds
     */
    private function assertUsersExist(array $userIds): void
    {
        $knownIds = app(User::class)
            ->whereIn('id', $userIds)
            ->where('status', 'approved')
            ->where('role', '!=', Role::GUEST->value)
            ->pluck('id')
            ->all();

        if (array_diff($userIds, $knownIds) !== []) {
            abort(422, 'One or more user ids are unknown.');
        }
    }

    /**
     * The report's current assignees as `{id, name, initials}`.
     *
     * @return array<int, array{id: string, name: string, initials: string}>
     */
    private function assigneeRows(Report $report): array
    {
        return AssigneeRow::fromAssignments(
            $report->assignments()->with('user:id,name')->get(),
        );
    }
}
