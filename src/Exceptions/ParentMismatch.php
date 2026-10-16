<?php

namespace Zipavlin\Redline\Exceptions;

use RuntimeException;

/**
 * A row addressed through the wrong parent (e.g. a comment PATCH whose report
 * path segment does not own the comment row). Purely a routing-shape failure:
 * HTTP answers a bare 404 exactly as the controller did before the actions
 * layer existed, and no MCP write tool can reach it (comments are immutable
 * through that surface).
 */
final class ParentMismatch extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('The row does not belong to the given report.');
    }
}
