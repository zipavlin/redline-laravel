<?php

use Illuminate\Support\Facades\DB;
use Zipavlin\Redline\Models\Report;
use Zipavlin\Redline\Models\User;

beforeEach(function () {
    ensureRedlineDirectories();
});

// ---------------------------------------------------------------------------
// Helpers
// ---------------------------------------------------------------------------

/**
 * Create a report with the given status. Defaults to being owned by a shared
 * developer user (owner identity is irrelevant for developer/admin callers).
 */
function createReportForStatus(string $status = 'new', ?string $userId = null): string
{
    if ($userId === null) {
        $userId = app(User::class)->firstOrCreate(
            ['email' => 'dev@example.com'],
            ['name' => 'Dev', 'role' => 'developer']
        )->id;
    }

    return app(Report::class)->create([
        'author_id' => $userId,
        'url' => 'https://example.com/',
        'location' => '/',
        'body' => 'Report body.',
        'status' => $status,
    ])->id;
}

/**
 * A reporter plus one of their own reports at the given status.
 *
 * @return array{0: string, 1: array<string, string>} [reportId, authHeaders]
 */
function reporterOwning(string $status): array
{
    $user = makeUser('reporter');
    $id = createReportForStatus($status, $user['id']);

    return [$id, ['Authorization' => 'Bearer '.makeAuthToken($user)]];
}

// ---------------------------------------------------------------------------
// Developer - valid transitions along the chain
// ---------------------------------------------------------------------------

it('lets a developer move new -> noticed', function () {
    $id = createReportForStatus('new');

    $this->patchJson("/redline/api/report/{$id}/status", ['status' => 'noticed'], authHeaders('developer'))
        ->assertStatus(200)
        ->assertJsonPath('status', 'noticed');
});

it('lets a developer move noticed -> progress', function () {
    $id = createReportForStatus('noticed');

    $this->patchJson("/redline/api/report/{$id}/status", ['status' => 'progress'], authHeaders('developer'))
        ->assertStatus(200)
        ->assertJsonPath('status', 'progress');
});

it('lets a developer skip progress: noticed -> resolved', function () {
    $id = createReportForStatus('noticed');

    $this->patchJson("/redline/api/report/{$id}/status", ['status' => 'resolved'], authHeaders('developer'))
        ->assertStatus(200)
        ->assertJsonPath('status', 'resolved');
});

it('lets a developer move progress -> resolved', function () {
    $id = createReportForStatus('progress');

    $this->patchJson("/redline/api/report/{$id}/status", ['status' => 'resolved'], authHeaders('developer'))
        ->assertStatus(200)
        ->assertJsonPath('status', 'resolved');
});

it('lets a developer reopen resolved -> progress', function () {
    $id = createReportForStatus('resolved');

    $this->patchJson("/redline/api/report/{$id}/status", ['status' => 'progress'], authHeaders('developer'))
        ->assertStatus(200)
        ->assertJsonPath('status', 'progress');
});

it('persists the status change', function () {
    $id = createReportForStatus('progress');

    $this->patchJson("/redline/api/report/{$id}/status", ['status' => 'resolved'], authHeaders('developer'));

    expect(app(Report::class)->find($id)->status)->toBe('resolved');
});

it('lets an admin change status', function () {
    $id = createReportForStatus('noticed');

    $this->patchJson("/redline/api/report/{$id}/status", ['status' => 'progress'], authHeaders('admin'))
        ->assertStatus(200);
});

// ---------------------------------------------------------------------------
// Developer - forbidden transitions
// ---------------------------------------------------------------------------

it('forbids a developer from skipping straight from new to progress', function () {
    $id = createReportForStatus('new');

    $this->patchJson("/redline/api/report/{$id}/status", ['status' => 'progress'], authHeaders('developer'))
        ->assertStatus(403);
});

it('forbids a developer from skipping straight from new to resolved', function () {
    $id = createReportForStatus('new');

    $this->patchJson("/redline/api/report/{$id}/status", ['status' => 'resolved'], authHeaders('developer'))
        ->assertStatus(403);
});

it('forbids a developer from setting status back to new', function () {
    $id = createReportForStatus('noticed');

    $this->patchJson("/redline/api/report/{$id}/status", ['status' => 'new'], authHeaders('developer'))
        ->assertStatus(403);
});

it('forbids a developer from confirming (done) - that is the reporter action', function () {
    $id = createReportForStatus('resolved');

    $this->patchJson("/redline/api/report/{$id}/status", ['status' => 'done'], authHeaders('developer'))
        ->assertStatus(403);
});

// ---------------------------------------------------------------------------
// Reporter - verify-fix loop on their own report
// ---------------------------------------------------------------------------

it('lets a report owner confirm resolved -> done', function () {
    [$id, $headers] = reporterOwning('resolved');

    $this->patchJson("/redline/api/report/{$id}/status", ['status' => 'done'], $headers)
        ->assertStatus(200)
        ->assertJsonPath('status', 'done');
});

it('lets a report owner reopen resolved -> progress', function () {
    [$id, $headers] = reporterOwning('resolved');

    $this->patchJson("/redline/api/report/{$id}/status", ['status' => 'progress'], $headers)
        ->assertStatus(200)
        ->assertJsonPath('status', 'progress');
});

it('forbids a report owner from setting a non-verify status', function () {
    [$id, $headers] = reporterOwning('resolved');

    $this->patchJson("/redline/api/report/{$id}/status", ['status' => 'noticed'], $headers)
        ->assertStatus(403);
});

it('forbids a report owner from acting on a report that is not yet resolved', function () {
    [$id, $headers] = reporterOwning('progress');

    $this->patchJson("/redline/api/report/{$id}/status", ['status' => 'done'], $headers)
        ->assertStatus(403);
});

it('forbids a reporter from changing a report they do not own', function () {
    $id = createReportForStatus('resolved'); // owned by dev@example.com

    $this->patchJson("/redline/api/report/{$id}/status", ['status' => 'done'], authHeaders('reporter'))
        ->assertStatus(403);
});

// ---------------------------------------------------------------------------
// Not found / validation
// ---------------------------------------------------------------------------

it('returns 404 when the report ID does not exist', function () {
    $this->patchJson('/redline/api/report/NONEXISTENT_ID/status', ['status' => 'resolved'], authHeaders('developer'))
        ->assertStatus(404);
});

it('returns 422 when status is missing', function () {
    $id = createReportForStatus('noticed');

    $this->patchJson("/redline/api/report/{$id}/status", [], authHeaders('developer'))
        ->assertStatus(422)
        ->assertJsonValidationErrors(['status']);
});

it('returns 422 when status is not a valid value', function () {
    $id = createReportForStatus('noticed');

    $this->patchJson("/redline/api/report/{$id}/status", ['status' => 'bogus'], authHeaders('developer'))
        ->assertStatus(422)
        ->assertJsonValidationErrors(['status']);
});

// ---------------------------------------------------------------------------
// Accept side effect - auto-assign the acting user (noticed -> progress only)
// ---------------------------------------------------------------------------

it('auto-assigns the developer who accepts a report (noticed -> progress)', function () {
    $author = makeUser('reporter');
    $id = createReportForStatus('noticed', $author['id']);
    $actor = makeUser('developer');

    $this->patchJson(
        "/redline/api/report/{$id}/status",
        ['status' => 'progress'],
        ['Authorization' => 'Bearer '.makeAuthToken($actor)],
    )->assertStatus(200)->assertJsonPath('status', 'progress');

    $members = DB::connection('redline')->table('redline_members')->where('report_id', $id);

    expect($members->where('user_id', $actor['id'])->count())->toBe(1)
        ->and($members->count())->toBe(1);
});

it('does not duplicate the member when the same developer accepts again', function () {
    $author = makeUser('reporter');
    $id = createReportForStatus('noticed', $author['id']);
    $actor = makeUser('developer');
    $url = "/redline/api/report/{$id}/status";
    $headers = ['Authorization' => 'Bearer '.makeAuthToken($actor)];

    $this->patchJson($url, ['status' => 'progress'], $headers)->assertStatus(200);

    // Re-arm the report and Accept again - the assignment is additive.
    app(Report::class)->find($id)->update(['status' => 'noticed']);
    $this->patchJson($url, ['status' => 'progress'], $headers)->assertStatus(200);

    expect(DB::connection('redline')->table('redline_members')
        ->where('report_id', $id)->where('user_id', $actor['id'])->count())->toBe(1);
});

it('does not assign anyone when a report is reopened (resolved -> progress)', function () {
    $author = makeUser('reporter');
    $id = createReportForStatus('resolved', $author['id']);
    $actor = makeUser('developer');

    $this->patchJson(
        "/redline/api/report/{$id}/status",
        ['status' => 'progress'],
        ['Authorization' => 'Bearer '.makeAuthToken($actor)],
    )->assertStatus(200);

    expect(DB::connection('redline')->table('redline_members')->where('report_id', $id)->count())->toBe(0);
});

it('does not assign anyone on transitions other than noticed -> progress', function () {
    $author = makeUser('reporter');
    $id = createReportForStatus('new', $author['id']);

    $this->patchJson("/redline/api/report/{$id}/status", ['status' => 'noticed'], authHeaders('developer'))
        ->assertStatus(200);

    expect(DB::connection('redline')->table('redline_members')->where('report_id', $id)->count())->toBe(0);
});

it('never adds the author when they accept their own report', function () {
    $author = makeUser('reporter');
    $id = createReportForStatus('noticed', $author['id']);

    $this->patchJson(
        "/redline/api/report/{$id}/status",
        ['status' => 'progress'],
        ['Authorization' => 'Bearer '.makeAuthToken($author)],
    )->assertStatus(200)->assertJsonPath('status', 'progress');

    expect(DB::connection('redline')->table('redline_members')->where('report_id', $id)->count())->toBe(0);
});

it('returns the author-excluded members list in the status response', function () {
    $author = makeUser('reporter');
    $id = createReportForStatus('noticed', $author['id']);
    $actor = makeUser('developer');

    $body = $this->patchJson(
        "/redline/api/report/{$id}/status",
        ['status' => 'progress'],
        ['Authorization' => 'Bearer '.makeAuthToken($actor)],
    )->assertStatus(200)->json();

    expect($body)->toHaveKey('members')
        ->and(array_column($body['members'], 'id'))->toBe([$actor['id']]);
});
