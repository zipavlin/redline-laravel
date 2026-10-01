<?php

namespace Zipavlin\Redline\Support;

use Zipavlin\Redline\Models\Base\Assignment;
use Zipavlin\Redline\Models\Base\User;

/**
 * Shared `{id, name, initials}` projection for a report's assignees.
 *
 * Used by both the write endpoints (AssignmentController) and the read
 * surface (the `assignees` include on Base\Report::querySchema()) so the two
 * can never drift into different shapes or orderings. Ordering is by name then
 * id - the `id` tiebreaker makes it a total order, so equal names never
 * reorder between calls.
 */
final class AssigneeRow
{
    /**
     * @param  iterable<int, Assignment>  $assignments  with their `user` relation loaded
     * @return array<int, array{id: string, name: string, initials: string}>
     */
    public static function fromAssignments(iterable $assignments): array
    {
        return collect($assignments)
            ->map(fn (Assignment $assignment) => $assignment->user)
            ->filter()
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
