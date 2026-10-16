<?php

namespace Zipavlin\Redline\Http\Controllers\Api;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Zipavlin\Redline\Actions\CommentActions;
use Zipavlin\Redline\Models\Comment;
use Zipavlin\Redline\Models\Report;

class CommentController extends Controller
{
    public function __construct(private readonly CommentActions $actions) {}

    /**
     * GET /redline/api/report/{report}/comment
     */
    public function index(Request $request, Report $report): JsonResponse
    {
        return response()->json($this->actions->list($request, $report));
    }

    /**
     * POST /redline/api/report/{report}/comment
     */
    public function store(Request $request, Report $report): JsonResponse
    {
        $comment = $this->actions->create($this->authUser($request), $report, $request->all());

        return response()->json($comment->toArray(), 201);
    }

    /**
     * PATCH /redline/api/report/{report}/comment/{comment}
     */
    public function updateBody(Request $request, Report $report, Comment $comment): JsonResponse
    {
        $comment = $this->actions->updateBody($report, $comment, $this->authUser($request), $request->all());

        return response()->json($comment->toArray());
    }

    /**
     * DELETE /redline/api/report/{report}/comment/{comment}
     */
    public function destroy(Request $request, Report $report, Comment $comment): Response
    {
        $this->actions->delete($report, $comment, $this->authUser($request));

        return response()->noContent();
    }
}
