<?php

namespace Zipavlin\Redline\Exceptions;

use Zipavlin\Redline\Enums\Status;

/**
 * A status transition the lifecycle chain and the caller's role both reject
 * (403 on HTTP).
 *
 * HTTP sends the fixed vocabulary (`Invalid status transition.`, see
 * ForbiddenAction); the MCP surface reports the role's allowed targets as an
 * actionable alternative list through actionableMessage(), since a tool caller
 * cannot retry blindly against a terse 403.
 */
final class InvalidStatusTransition extends ForbiddenAction
{
    /**
     * @param  list<Status>  $allowedTargets  what THIS user could still move
     *                                        this report to right now, role
     *                                        intersected with the chain
     */
    public function __construct(
        public readonly Status $from,
        public readonly Status $target,
        public readonly array $allowedTargets,
    ) {
        parent::__construct('Invalid status transition.');
    }

    /**
     * The tool-facing variant: same vocabulary, plus the legal alternatives
     * (MCP contract section 4 - an actionable error, never an empty success).
     */
    public function actionableMessage(): string
    {
        $targets = array_map(fn (Status $status) => $status->value, $this->allowedTargets);

        if ($targets === []) {
            return sprintf(
                'Invalid status transition: a %s report cannot move to [%s], and your role has no legal target here.',
                $this->from->value,
                $this->target->value,
            );
        }

        return sprintf(
            'Invalid status transition: a %s report cannot move to [%s]. Allowed targets for your role: [%s].',
            $this->from->value,
            $this->target->value,
            implode(', ', $targets),
        );
    }
}
