<?php

namespace Zipavlin\Redline\Exceptions;

use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * A mutation the acting user's role does not permit (the 403 family).
 *
 * It is an HTTP 403 by construction, so the framework answers it exactly as it
 * answered `abort(403, $message)` and never logs it as an incident. The message
 * is the HTTP surface's own text and must stay in the vocabulary the API has
 * always sent (`Forbidden.`, `Feature disabled.`). The MCP tools catch it
 * before it reaches the framework and report it as a tool error.
 */
class ForbiddenAction extends HttpException
{
    public function __construct(string $message = 'Forbidden.')
    {
        parent::__construct(403, $message);
    }
}
