<?php

namespace Zipavlin\Redline\Exceptions;

use RuntimeException;

/**
 * A mutation the acting user's role does not permit (the 403 family).
 *
 * The message is the HTTP surface's own text: the controller maps this straight
 * to `abort(403, $message)`, so the wording must stay in the exact vocabulary
 * the HTTP API has always sent (`Forbidden.`, `Feature disabled.`).
 */
class ForbiddenAction extends RuntimeException
{
    public function __construct(string $message = 'Forbidden.')
    {
        parent::__construct($message);
    }
}
