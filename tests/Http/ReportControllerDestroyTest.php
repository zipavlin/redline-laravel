<?php

use Zipavlin\Redline\Models\Base\Report;
use Zipavlin\Redline\Models\Base\User;

beforeEach(function () {
    ensureRedlineDirectories();
});

// ---------------------------------------------------------------------------
// Helper
// ---------------------------------------------------------------------------

function createReportForDestroy(string $ownerRole = 'reporter'): array
{
    static $counter = 0;
    $counter++;

    $user = app(User::class)->create([
        'name' => "Owner {$counter}",
        'email' => "owner{$counter}@example.com",
        'role' => $ownerRole,
    ]);

    $report = app(Report::class)->create([
        'author_id' => $user->id,
        'url' => 'https://example.com/',
        'location' => '/',
        'body' => 'Report.',
        'status' => 'new',
    ]);

    return ['report' => $report->toArray(), 'user' => $user->toArray()];
}

// ---------------------------------------------------------------------------
// Happy path
// ---------------------------------------------------------------------------

it('returns 204 when an admin deletes any report', function () {
    ['report' => $report] = createReportForDestroy('reporter');

    $this->deleteJson("/redline/api/report/{$report['id']}", [], authHeaders('admin'))
        ->assertStatus(204)
        ->assertNoContent();
});

it('makes the report unfindable after deletion', function () {
    ['report' => $report] = createReportForDestroy();

    $this->deleteJson("/redline/api/report/{$report['id']}", [], authHeaders('admin'))
        ->assertStatus(204);

    expect(app(Report::class)->find($report['id']))->toBeNull();
});

it('allows a reporter to delete their own report', function () {
    ['report' => $report, 'user' => $user] = createReportForDestroy('reporter');

    $token = makeAuthToken($user);
    $headers = ['Authorization' => "Bearer {$token}"];

    $this->deleteJson("/redline/api/report/{$report['id']}", [], $headers)
        ->assertStatus(204);
});

it('only deletes the targeted report and leaves others intact', function () {
    ['report' => $keep] = createReportForDestroy();
    ['report' => $remove] = createReportForDestroy();

    $this->deleteJson("/redline/api/report/{$remove['id']}", [], authHeaders('admin'))
        ->assertStatus(204);

    expect(app(Report::class)->find($keep['id']))->not->toBeNull();
    expect(app(Report::class)->find($remove['id']))->toBeNull();
});

// ---------------------------------------------------------------------------
// Permission
// ---------------------------------------------------------------------------

it('returns 403 when a reporter tries to delete someone elses report', function () {
    ['report' => $report] = createReportForDestroy('reporter');

    // Different reporter token.
    $this->deleteJson("/redline/api/report/{$report['id']}", [], authHeaders('reporter'))
        ->assertStatus(403);
});

it('returns 403 when a developer tries to delete someone elses report', function () {
    ['report' => $report] = createReportForDestroy('reporter');

    // A developer is not an admin — deletion is owner-or-admin only.
    $this->deleteJson("/redline/api/report/{$report['id']}", [], authHeaders('developer'))
        ->assertStatus(403);
});

// ---------------------------------------------------------------------------
// Not found
// ---------------------------------------------------------------------------

it('returns 404 when the report ID does not exist', function () {
    $this->deleteJson('/redline/api/report/NONEXISTENT_ID', [], authHeaders('developer'))
        ->assertStatus(404);
});

it('returns 404 on a second delete of the same report', function () {
    ['report' => $report] = createReportForDestroy();

    $this->deleteJson("/redline/api/report/{$report['id']}", [], authHeaders('admin'))->assertStatus(204);
    $this->deleteJson("/redline/api/report/{$report['id']}", [], authHeaders('admin'))->assertStatus(404);
});
