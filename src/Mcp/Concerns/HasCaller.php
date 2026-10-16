<?php

namespace Zipavlin\Redline\Mcp\Concerns;

use Illuminate\Http\Request as HttpRequest;
use Laravel\Mcp\Response;
use Zipavlin\Redline\Models\User;

/**
 * Who is calling a tool, and the single no-caller rejection every tool shares.
 * Read and write tools both compose this; CanMapActionErrors layers the Actions
 * failure mapping on top for the write path.
 */
trait HasCaller
{
    /**
     * The authenticated caller, or null when the wire path was bypassed (tests
     * exercise the no-auth branch, but it is unreachable behind RedlineApiAuth).
     */
    protected function caller(HttpRequest $incoming): ?User
    {
        $user = $incoming->user();

        return $user instanceof User ? $user : null;
    }

    /**
     * The standard no-caller rejection, shared verbatim by every tool.
     */
    protected function unauthenticatedResponse(): Response
    {
        return Response::error('No authenticated Redline user on this request.');
    }
}
