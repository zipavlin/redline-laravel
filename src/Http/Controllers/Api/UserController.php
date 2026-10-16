<?php

namespace Zipavlin\Redline\Http\Controllers\Api;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Zipavlin\Redline\Actions\UserActions;

/**
 * User management - thin over UserActions (the account lifecycle plus the
 * grammar-driven reads). Only the list is open to any authenticated role;
 * both the write gates and the grammar's per-role PII gates live in the
 * action / schema.
 */
class UserController extends Controller
{
    public function __construct(private readonly UserActions $actions) {}

    /**
     * GET /redline/api/user (any authenticated user; PII behind explicit include[])
     */
    public function index(Request $request): JsonResponse
    {
        return response()->json($this->actions->list($request));
    }

    /**
     * GET /redline/api/user/aggregate (admin only)
     */
    public function aggregate(Request $request): JsonResponse
    {
        return response()->json($this->actions->aggregate($request));
    }

    /**
     * GET /redline/api/user/{user} (admin only)
     */
    public function show(Request $request, $user): JsonResponse
    {
        return response()->json($this->actions->show($request, $user));
    }

    /**
     * POST /redline/api/user (admin only)
     */
    public function store(Request $request): JsonResponse
    {
        return response()->json($this->actions->store($request), 201);
    }

    /**
     * PATCH /redline/api/user/{user} (admin only)
     */
    public function update(Request $request, $user): JsonResponse
    {
        return response()->json($this->actions->update($request, $user));
    }

    /**
     * DELETE /redline/api/user/{user} (admin only)
     */
    public function destroy(Request $request, $user): Response
    {
        $this->actions->destroy($request, $user);

        return response()->noContent();
    }

    /**
     * POST /redline/api/user/{user}/approve (admin only)
     */
    public function approve(Request $request, $user): JsonResponse
    {
        return response()->json($this->actions->approve($request, $user));
    }

    /**
     * POST /redline/api/user/{user}/reset-password (admin only)
     */
    public function resetPassword(Request $request, $user): JsonResponse
    {
        return response()->json($this->actions->resetPassword($request, $user));
    }
}
