<?php

use Zipavlin\Redline\Models\Base\Comment;
use Zipavlin\Redline\Models\Base\Report;
use Zipavlin\Redline\Models\Base\User;

beforeEach(function () {
    ensureRedlineDirectories();
});

// ---------------------------------------------------------------------------
// Helper - create a report owned by a fixed reporter user.
// ---------------------------------------------------------------------------

function createReportForComment(): array
{
    $user = app(User::class)->firstOrCreate(
        ['email' => 'reporter@example.com'],
        ['name' => 'Reporter', 'role' => 'reporter']
    );

    $report = app(Report::class)->create([
        'author_id' => $user->id,
        'url' => 'https://example.com/',
        'location' => '/',
        'body' => 'Original report.',
        'status' => 'new',
    ]);

    return $report->toArray();
}

// ---------------------------------------------------------------------------
// Happy path
// ---------------------------------------------------------------------------

it('returns 201 with the new comment on a valid request', function () {
    $report = createReportForComment();
    $headers = authHeaders('developer');

    $body = $this->postJson("/redline/api/report/{$report['id']}/comment", [
        'body' => 'Fixed in commit abc123 - please retest.',
    ], $headers)
        ->assertStatus(201)
        ->json();

    expect($body['body'])->toBe('Fixed in commit abc123 - please retest.')
        ->and($body['id'])->toBeString()->not->toBeEmpty();
});

it('assigns a UUID id and created_at to the new comment', function () {
    $report = createReportForComment();

    $body = $this->postJson("/redline/api/report/{$report['id']}/comment", [
        'body' => 'Looks good.',
    ], authHeaders('developer'))
        ->assertStatus(201)
        ->json();

    expect($body['id'])->toBeString()->not->toBeEmpty()
        ->and($body['created_at'])->toBeString()->not->toBeEmpty();
});

it('persists the comment so subsequent listing returns it', function () {
    $report = createReportForComment();
    $headers = authHeaders('developer');

    $this->postJson("/redline/api/report/{$report['id']}/comment", ['body' => 'First'], $headers);
    $this->postJson("/redline/api/report/{$report['id']}/comment", ['body' => 'Second'], $headers);

    $comments = $this->getJson("/redline/api/report/{$report['id']}/comment", $headers)
        ->assertStatus(200)
        ->json();

    expect($comments)->toHaveCount(2)
        ->and($comments[0]['body'])->toBe('First')
        ->and($comments[1]['body'])->toBe('Second');
});

it('allows reporter role to comment', function () {
    $report = createReportForComment();

    $this->postJson("/redline/api/report/{$report['id']}/comment", [
        'body' => 'Still broken.',
    ], authHeaders('reporter'))
        ->assertStatus(201);
});

// ---------------------------------------------------------------------------
// Not found
// ---------------------------------------------------------------------------

it('returns 404 when the report does not exist', function () {
    $this->postJson('/redline/api/report/NONEXISTENT/comment', [
        'body' => 'Test.',
    ], authHeaders('developer'))
        ->assertStatus(404);
});

// ---------------------------------------------------------------------------
// Validation
// ---------------------------------------------------------------------------

it('returns 422 when body is missing', function () {
    $report = createReportForComment();

    $this->postJson("/redline/api/report/{$report['id']}/comment", [], authHeaders('developer'))
        ->assertStatus(422)
        ->assertJsonValidationErrors(['body']);
});

// ---------------------------------------------------------------------------
// Auth
// ---------------------------------------------------------------------------

it('returns 401 when no token is provided', function () {
    $report = createReportForComment();

    $this->postJson("/redline/api/report/{$report['id']}/comment", ['body' => 'Comment.'])
        ->assertStatus(401);
});

// ---------------------------------------------------------------------------
// Comment update - author-only body edits
// ---------------------------------------------------------------------------

it('allows the comment author to update the comment body', function () {
    $author = makeUser('reporter');
    $report = createReportForComment();

    $comment = app(Comment::class)->create([
        'report_id' => $report['id'],
        'author_id' => $author['id'],
        'body' => 'Original comment.',
    ]);

    $this->patchJson(
        "/redline/api/report/{$report['id']}/comment/{$comment->id}",
        ['body' => 'Edited by the author.'],
        ['Authorization' => 'Bearer '.makeAuthToken($author)],
    )->assertStatus(200)->assertJsonPath('body', 'Edited by the author.');
});

it('forbids editing someone elses comment body for another reporter, a developer and an admin', function () {
    $author = makeUser('reporter');
    $report = createReportForComment();

    $comment = app(Comment::class)->create([
        'report_id' => $report['id'],
        'author_id' => $author['id'],
        'body' => 'Original comment.',
    ]);

    foreach (['reporter', 'developer', 'admin'] as $role) {
        $this->patchJson(
            "/redline/api/report/{$report['id']}/comment/{$comment->id}",
            ['body' => 'hijacked'],
            authHeaders($role),
        )->assertStatus(403);
    }
});

// ---------------------------------------------------------------------------
// Comment delete - owner or admin
// ---------------------------------------------------------------------------

it('allows the comment author to delete their own comment', function () {
    $author = makeUser('reporter');
    $report = createReportForComment();

    $comment = app(Comment::class)->create([
        'report_id' => $report['id'],
        'author_id' => $author['id'],
        'body' => 'Delete me.',
    ]);

    $this->deleteJson(
        "/redline/api/report/{$report['id']}/comment/{$comment->id}",
        [],
        ['Authorization' => 'Bearer '.makeAuthToken($author)],
    )->assertStatus(204);

    expect(app(Comment::class)->find($comment->id))->toBeNull();
});

it('allows an admin to delete any comment', function () {
    $author = makeUser('reporter');
    $report = createReportForComment();

    $comment = app(Comment::class)->create([
        'report_id' => $report['id'],
        'author_id' => $author['id'],
        'body' => 'Delete me.',
    ]);

    $this->deleteJson(
        "/redline/api/report/{$report['id']}/comment/{$comment->id}",
        [],
        authHeaders('admin'),
    )->assertStatus(204);
});

it('forbids a developer and another reporter from deleting someone elses comment', function () {
    $author = makeUser('reporter');
    $report = createReportForComment();

    $comment = app(Comment::class)->create([
        'report_id' => $report['id'],
        'author_id' => $author['id'],
        'body' => 'Keep me.',
    ]);

    $this->deleteJson(
        "/redline/api/report/{$report['id']}/comment/{$comment->id}",
        [],
        authHeaders('developer'),
    )->assertStatus(403);

    $this->deleteJson(
        "/redline/api/report/{$report['id']}/comment/{$comment->id}",
        [],
        authHeaders('reporter'),
    )->assertStatus(403);
});

it('returns 404 when the comment belongs to a different report than the URL parent', function () {
    $author = makeUser('reporter');
    $reportA = createReportForComment();
    $reportB = createReportForComment();

    $comment = app(Comment::class)->create([
        'report_id' => $reportA['id'],
        'author_id' => $author['id'],
        'body' => 'Attached to report A.',
    ]);

    // PATCH through the wrong parent report.
    $this->patchJson(
        "/redline/api/report/{$reportB['id']}/comment/{$comment->id}",
        ['body' => 'nope'],
        ['Authorization' => 'Bearer '.makeAuthToken($author)],
    )->assertStatus(404);

    // DELETE through the wrong parent report.
    $this->deleteJson(
        "/redline/api/report/{$reportB['id']}/comment/{$comment->id}",
        [],
        ['Authorization' => 'Bearer '.makeAuthToken($author)],
    )->assertStatus(404);
});
