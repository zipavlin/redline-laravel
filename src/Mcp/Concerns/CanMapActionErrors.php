<?php

namespace Zipavlin\Redline\Mcp\Concerns;

use Illuminate\Validation\ValidationException;
use Laravel\Mcp\Response;
use Laravel\Mcp\Support\ValidationMessages;
use Zipavlin\Redline\Exceptions\ForbiddenAction;
use Zipavlin\Redline\Exceptions\InvalidStatusTransition;
use Zipavlin\Redline\Exceptions\ParentMismatch;

/**
 * MCP tool plumbing: who is calling, and how the Actions layer's failures
 * surface as actionable tool errors (MCP contract section 4). Reads pay the
 * same toll as writes wherever they run through an action's gate.
 *
 * Writes never go through synthetic HTTP requests - arguments come off the
 * MCP request array and the user off the incoming authenticated request.
 */
trait CanMapActionErrors
{
    use HasCaller;

    /**
     * Run one Actions-layer call and map its failures:
     *
     *  ValidationException          -> the 422-equivalent actionable text
     *  InvalidStatusTransition      -> 403-equivalent + the role's allowed targets
     *  ForbiddenAction (the family) -> the exact HTTP 403 vocabulary
     *  ParentMismatch               -> 404-equivalent, the tool's not-found text
     *
     * @param  callable(): Response  $run
     */
    protected function mapFailures(callable $run): Response
    {
        try {
            return $run();
        } catch (InvalidStatusTransition $exception) {
            return Response::error($exception->actionableMessage());
        } catch (ForbiddenAction $exception) {
            return Response::error($exception->getMessage());
        } catch (ParentMismatch $exception) {
            return Response::error($exception->getMessage());
        } catch (ValidationException $exception) {
            return Response::error(ValidationMessages::from($exception));
        }
    }
}
