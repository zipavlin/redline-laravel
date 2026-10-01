<?php

namespace Zipavlin\Redline\Http\Controllers\Api;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Zipavlin\Redline\Enums\Feature;
use Zipavlin\Redline\Enums\Operation;
use Zipavlin\Redline\Models\Comment;
use Zipavlin\Redline\Models\Report;

class CommentController extends Controller
{
    /**
     * GET /redline/api/report/{report}/comment
     */
    public function index(Request $request, Report $report): JsonResponse
    {
        $this->checkUser($request, Operation::LIST);

        $comments = app(Comment::class)
            ->with('author:id,name')
            ->where('report_id', $report->id)
            ->orderBy('created_at')
            ->get();

        return response()->json($comments->toArray());
    }

    /**
     * POST /redline/api/report/{report}/comment
     */
    public function store(Request $request, Report $report): JsonResponse
    {
        $this->checkFeature(Feature::COMMENTS);
        $this->checkUser($request, Operation::CREATE);

        $validated = $request->validate([
            'body' => ['required', 'string'],
        ]);

        $comment = app(Comment::class)->create([
            'report_id' => $report->id,
            'author_id' => $this->authUser($request)->id,
            'body' => $this->sanitizeHtml($validated['body']),
        ]);

        return response()->json($comment->fresh()->load('author')->toArray(), 201);
    }

    /**
     * PATCH /redline/api/report/{report}/comment/{comment}
     *
     * Author only - developers/admins must not rewrite someone else's body.
     */
    public function update(Request $request, Report $report, Comment $comment): JsonResponse
    {
        $this->checkUser($request, Operation::EDIT, $comment);
        $this->checkParent(parent: $report, child: $comment);

        $validated = $request->validate([
            'body' => ['required', 'string'],
        ]);

        $validated['body'] = $this->sanitizeHtml($validated['body']);

        $comment->update($validated);

        return response()->json($comment->fresh()->load('author')->toArray());
    }

    /**
     * DELETE /redline/api/report/{report}/comment/{comment}
     *
     * Own or admin.
     */
    public function destroy(Request $request, Report $report, Comment $comment): Response|JsonResponse
    {
        $this->checkUser($request, Operation::DELETE, $comment);
        $this->checkParent(parent: $report, child: $comment);

        $comment->delete();

        return response()->noContent();
    }
}
