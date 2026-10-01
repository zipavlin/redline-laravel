<?php

namespace Zipavlin\Redline\Support;

use Zipavlin\Redline\Models\User;

/**
 * Shared `{id, name, initials}` projection for a report's members.
 *
 * Used by both the write endpoints (MemberController) and the read surface
 * (the `members` include on Base\Report::querySchema()) so the two can never
 * drift into different shapes or orderings. Ordering is by name then id - the
 * `id` tiebreaker makes it a total order, so equal names never reorder between
 * calls.
 */
final class Members
{
    /**
     * @param  iterable<int, User>  $users
     * @return array<int, array{id: string, name: string, initials: string}>
     */
    public static function fromUsers(iterable $users): array
    {
        return collect($users)
            ->sortBy([['name', 'asc'], ['id', 'asc']])
            ->map(fn (User $user) => [
                'id' => $user->id,
                'name' => $user->name,
                'initials' => $user->initials,
            ])
            ->values()
            ->all();
    }
}
