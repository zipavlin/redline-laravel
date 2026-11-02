<?php

namespace Zipavlin\Redline\Exceptions;

use Illuminate\Contracts\Support\Responsable;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * An authentication denial on the redline API surface (contract v3).
 *
 * The HTTP status is 403, never 401: Chrome pops the native Basic-Auth dialog
 * whenever a browser fetch receives a 401 - challenge-less or not - so a 401
 * from redline's surface triggers the popup on hosts behind server-side Basic
 * Auth. The body keeps the historical `message` and adds the stable
 * `error: unauthenticated` discriminator; plain 403s (ForbiddenAction,
 * FeatureDisabled) deliberately carry no marker. The MCP route is not part of
 * this contract: RedlineApiAuth answers it with contract-v2 401s instead.
 *
 * Rendered by the framework itself (Responsable), so no host exception
 * handler registration is needed for the envelope.
 */
final class Unauthenticated extends HttpException implements Responsable
{
    public const MARKER = 'unauthenticated';

    public function __construct(string $message = 'Unauthenticated.')
    {
        parent::__construct(403, $message);
    }

    public function toResponse($request): JsonResponse
    {
        return response()->json([
            'message' => $this->getMessage(),
            'error' => self::MARKER,
        ], 403);
    }
}
