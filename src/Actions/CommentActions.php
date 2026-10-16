<?php

namespace Zipavlin\Redline\Actions;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Zipavlin\Redline\Enums\Feature;
use Zipavlin\Redline\Enums\Operation;
use Zipavlin\Redline\Models\Comment;
use Zipavlin\Redline\Models\Report;
use Zipavlin\Redline\Models\User;
use Zipavlin\Redline\Support\HtmlSanitizer;

/**
 * THE comment read+write path, shared by the HTTP controller and the MCP
 * `comment_create` tool - mirror of ReportActions for comment bodies.
 */
class CommentActions extends Action
{
    /**
     * A report's comment list, oldest first (HTTP GET
     * /report/{report}/comment). Same rows the HTTP endpoint has always
     * returned; the MCP contract pages over its own envelope instead.
     */
    public function list(Request $request, Report $report): array
    {
        $this->assertAllowed($this->requestUser($request), Operation::LIST);

        return $this->query($report)->get()->toArray();
    }

    /**
     * One page of the same list, for the MCP `comment_list` envelope - the LIST
     * gate and the query itself are shared with list(), so the two surfaces
     * cannot drift apart. The id tiebreak keeps paging deterministic where
     * created_at collides (the grammar's default sort does the same).
     *
     * @return LengthAwarePaginator<int, Comment>
     */
    public function page(User $user, Report $report, int $perPage, int $page): LengthAwarePaginator
    {
        $this->assertAllowed($user, Operation::LIST);

        return $this->query($report)->orderBy('id')->paginate($perPage, page: $page);
    }

    /**
     * A report's comments, oldest first - the one query both readers run.
     *
     * @return Builder<Comment>
     */
    private function query(Report $report): Builder
    {
        return app(Comment::class)
            ->with('author:id,name')
            ->where('report_id', $report->id)
            ->orderBy('created_at');
    }

    /**
     * Create a comment (HTTP POST /report/{report}/comment and MCP
     * comment_create). Mirrors the HTTP POST gate exactly: the comments feature
     * must be enabled, then the CREATE role gate, then the body validation.
     *
     * @param  array<string, mixed>  $payload
     */
    public function create(User $user, Report $report, array $payload): Comment
    {
        $this->assertFeature(Feature::COMMENTS);
        $this->assertAllowed($user, Operation::CREATE);

        $validated = Validator::make($payload, self::createRules())->validate();

        $comment = app(Comment::class)->create([
            'report_id' => $report->id,
            'author_id' => $user->id,
            'body' => HtmlSanitizer::clean((string) $validated['body']),
        ]);

        return $comment->fresh()->load('author');
    }

    /**
     * Rewrite a comment's body (HTTP PATCH /report/{report}/comment/{comment}).
     * Author only, and the addressed parent pair must match. Bodies stay
     * immutable through MCP - there is no edit tool.
     *
     * @param  array<string, mixed>  $payload
     */
    public function updateBody(Report $report, Comment $comment, User $user, array $payload): Comment
    {
        $this->assertAllowed($user, Operation::EDIT, $comment);
        $this->assertRelationship($report, $comment);

        $validated = Validator::make($payload, ['body' => ['required', 'string']])->validate();
        $validated['body'] = HtmlSanitizer::clean((string) $validated['body']);

        $comment->update($validated);

        return $comment->fresh()->load('author');
    }

    /**
     * Delete a comment (HTTP DELETE /report/{report}/comment/{comment}).
     * Own or admin, and the addressed parent pair must match.
     */
    public function delete(Report $report, Comment $comment, User $user): void
    {
        $this->assertAllowed($user, Operation::DELETE, $comment);
        $this->assertRelationship($report, $comment);

        $comment->delete();
    }

    /**
     * The POST rule set (assertingFeature happens before this - see create()).
     *
     * @return array<string, mixed>
     */
    public static function createRules(): array
    {
        return [
            'body' => ['required', 'string'],
        ];
    }
}
