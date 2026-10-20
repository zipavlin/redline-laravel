<?php

namespace Zipavlin\Redline\Actions;

use Illuminate\Http\Request;
use Zipavlin\Redline\Enums\Operation;
use Zipavlin\Redline\Exceptions\ForbiddenAction;
use Zipavlin\Redline\Models\Report;
use Zipavlin\Redline\Models\User;
use Zipavlin\Redline\Support\Members;

/**
 * Report members - the write side of the report-user pivot (HTTP
 * POST/DELETE /report/{report}/member). Reads live on Report::querySchema()
 * (include[members], filter[member]); these two operations mutate pairs and
 * always answer with the report's full, name-sorted member list (author
 * excluded) so the client never has to refetch or reconcile.
 */
class MemberActions extends Action
{
    /**
     * Additive and idempotent: pairs that already exist are left alone, so
     * re-posting the same list is a no-op rather than a 409 or a duplicate
     * row. Returns the report's full member projection.
     */
    public function store(User $user, Request $request, Report $report): array
    {
        $this->assertAllowed($user, Operation::UPDATE, $report);

        // The author can never be a member; an author id is dropped like an
        // already-existing pair rather than rejected.
        $userIds = $this->withoutAuthor($report, $this->validatedUserIds($request));
        $this->assertUsersExist($userIds);

        // syncWithoutDetaching inserts only the missing pairs and leaves existing
        // ones untouched, so it is additive + idempotent without a unique-constraint
        // exception when a concurrent POST wins the race.
        $report->members()->syncWithoutDetaching($userIds);
        // Drops any pre-loaded relation copy so the returned projection reads
        // fresh pairs, never a stale in-memory list.
        $report->unsetRelation('members');

        return Members::forReport($report);
    }

    /**
     * Remove the given member pairs. Removing a pair that does not exist is a
     * no-op (idempotent), matching store(). Returns the report's full member
     * projection.
     */
    public function destroy(User $user, Request $request, Report $report): array
    {
        $userIds = $this->validatedUserIds($request);

        if (! $this->canRemove($user, $report, $userIds)) {
            throw new ForbiddenAction;
        }

        $report->members()->detach($userIds);
        $report->unsetRelation('members');

        return Members::forReport($report);
    }

    /**
     * Drop the report's author from an incoming member id list: posting the
     * author is a no-op (like an already-existing pair), never a validation
     * error. The author is the `author` relation, while `members` holds
     * additional collaborators.
     *
     * @param  array<int, string>  $userIds
     * @return array<int, string>
     */
    private function withoutAuthor(Report $report, array $userIds): array
    {
        return array_values(array_filter(
            $userIds,
            fn (string $userId) => ! $report->isAuthoredBy($userId),
        ));
    }

    /**
     * Membership management is developer/author-only, with one exception: a
     * reporter may remove exactly themselves. Any other target list, including
     * one that pairs self with someone else, falls back to Operation::UPDATE.
     * The self-removal branch also requires an eligible identity (approved,
     * non-guest) - the same rule the write side enforces on members.
     *
     * @param  array<int, string>  $userIds
     */
    private function canRemove(User $user, Report $report, array $userIds): bool
    {
        if ($user->can(Operation::UPDATE, $report)) {
            return true;
        }

        return $userIds === [$user->id]
            && $user->isEligibleMember;
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
            ->whereEligibleMember()
            ->pluck('id')
            ->all();

        if (array_diff($userIds, $knownIds) !== []) {
            abort(422, 'One or more user ids are unknown.');
        }
    }
}
