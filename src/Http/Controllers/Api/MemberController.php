<?php

namespace Zipavlin\Redline\Http\Controllers\Api;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Zipavlin\Redline\Enums\Operation;
use Zipavlin\Redline\Enums\Role;
use Zipavlin\Redline\Models\Report;
use Zipavlin\Redline\Models\User;
use Zipavlin\Redline\Support\Members;

/**
 * Report members - the write side of the report-user pivot.
 *
 * Reads (include[members], filter[member]) live on Base\Report::querySchema();
 * these two endpoints mutate pairs and always answer with the report's full,
 * name-sorted member list so the client never has to refetch or reconcile.
 */
class MemberController extends Controller
{
    /**
     * POST /redline/api/report/{report}/member
     *
     * Additive and idempotent: pairs that already exist are left alone, so
     * re-posting the same list is a no-op rather than a 409 or a duplicate row.
     */
    public function store(Request $request, Report $report): JsonResponse
    {
        $this->checkUser($request, Operation::UPDATE, $report);

        $userIds = $this->validatedUserIds($request);
        $this->assertUsersExist($userIds);

        // syncWithoutDetaching inserts only the missing pairs and leaves existing
        // ones untouched, so it is additive + idempotent without a unique-constraint
        // exception when a concurrent POST wins the race.
        $report->members()->syncWithoutDetaching($userIds);

        return response()->json(['members' => $this->memberRows($report)]);
    }

    /**
     * DELETE /redline/api/report/{report}/member
     *
     * Removing a pair that does not exist is a no-op (idempotent), matching store().
     */
    public function destroy(Request $request, Report $report): JsonResponse
    {
        $this->checkUser($request, Operation::UPDATE, $report);

        $userIds = $this->validatedUserIds($request);

        $report->members()->detach($userIds);

        return response()->json(['members' => $this->memberRows($report)]);
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
     * Reject the whole request when any id does not resolve to a user eligible to
     * be a member, rather than silently skipping it - a partial attach would hide
     * a client bug. Pending accounts are not eligible (they cannot log in), and
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
     * The report's current members as `{id, name, initials}`.
     *
     * @return array<int, array{id: string, name: string, initials: string}>
     */
    private function memberRows(Report $report): array
    {
        return Members::fromUsers($report->members()->get());
    }
}
