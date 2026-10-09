<?php

use Illuminate\Support\Carbon;
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
 * Create a report, optionally with an explicit deadline, owned by a shared
 * developer (owner identity is irrelevant for developer/admin callers).
 */
function createReportForDueAt(?string $dueAt = null, ?string $userId = null): string
{
    if ($userId === null) {
        $userId = app(User::class)->firstOrCreate(
            ['email' => 'dev@example.com'],
            ['name' => 'Dev', 'role' => 'developer']
        )->id;
    }

    $attributes = [
        'author_id' => $userId,
        'url' => 'https://example.com/',
        'location' => '/',
        'body' => 'Report body.',
        'status' => 'new',
    ];

    if ($dueAt !== null) {
        $attributes['due_at'] = $dueAt;
    }

    return app(Report::class)->create($attributes)->id;
}

/**
 * A reporter plus one of their own reports with an optional deadline.
 *
 * @return array{0: string, 1: array<string, string>} [reportId, authHeaders]
 */
function reporterOwningDueAtReport(?string $dueAt = null): array
{
    $user = makeUser('reporter');
    $id = createReportForDueAt($dueAt, $user['id']);

    return [$id, ['Authorization' => 'Bearer '.makeAuthToken($user)]];
}

// ---------------------------------------------------------------------------
// Setting the deadline
// ---------------------------------------------------------------------------

it('lets a developer set a due date', function () {
    $this->travelTo(Carbon::parse('2026-10-05T12:00:00Z'));
    $id = createReportForDueAt();

    $body = $this->patchJson("/redline/api/report/{$id}/due_at", ['due_at' => '2026-10-07T09:30:00Z'], authHeaders('developer'))
        ->assertStatus(200)
        ->json();

    $expected = Carbon::parse('2026-10-07T09:30:00Z');

    expect(Carbon::parse($body['due_at'])->equalTo($expected))->toBeTrue()
        ->and(app(Report::class)->find($id)->due_at->equalTo($expected))->toBeTrue();
});

it('lets a report owner set their own report due date', function () {
    [$id, $headers] = reporterOwningDueAtReport();

    $this->patchJson("/redline/api/report/{$id}/due_at", ['due_at' => '2026-11-01T08:00:00Z'], $headers)
        ->assertStatus(200)
        ->assertJsonPath('due_at', '2026-11-01T08:00:00.000000Z');
});

it('returns the fresh row without disturbing other fields', function () {
    $id = createReportForDueAt();
    $before = app(Report::class)->find($id);

    $body = $this->patchJson("/redline/api/report/{$id}/due_at", ['due_at' => '2026-10-07T09:00:00Z'], authHeaders('developer'))
        ->assertStatus(200)
        ->json();

    expect($body['id'])->toBe($id)
        ->and($body['status'])->toBe($before->status)
        ->and($body['priority'])->toBe($before->priority);
});

// ---------------------------------------------------------------------------
// Clearing the deadline
// ---------------------------------------------------------------------------

it('clears the due date with an explicit null', function () {
    $id = createReportForDueAt('2026-10-07T09:00:00Z');

    $body = $this->patchJson("/redline/api/report/{$id}/due_at", ['due_at' => null], authHeaders('developer'))
        ->assertStatus(200)
        ->json();

    expect($body['due_at'])->toBeNull()
        ->and(app(Report::class)->find($id)->due_at)->toBeNull();
});

// ---------------------------------------------------------------------------
// Authorization
// ---------------------------------------------------------------------------

it('forbids a reporter from setting a due date on a report they do not own', function () {
    $id = createReportForDueAt(); // owned by dev@example.com

    $this->patchJson("/redline/api/report/{$id}/due_at", ['due_at' => '2026-10-07T09:00:00Z'], authHeaders('reporter'))
        ->assertStatus(403);
});

// ---------------------------------------------------------------------------
// Validation / not found
// ---------------------------------------------------------------------------

it('returns 422 when due_at is absent', function () {
    $id = createReportForDueAt();

    $this->patchJson("/redline/api/report/{$id}/due_at", [], authHeaders('developer'))
        ->assertStatus(422)
        ->assertJsonValidationErrors(['due_at']);
});

it('returns 422 when due_at is not a date', function () {
    $id = createReportForDueAt();

    $this->patchJson("/redline/api/report/{$id}/due_at", ['due_at' => 'not-a-date'], authHeaders('developer'))
        ->assertStatus(422)
        ->assertJsonValidationErrors(['due_at']);
});

it('returns 404 when the report ID does not exist', function () {
    $this->patchJson('/redline/api/report/NONEXISTENT_ID/due_at', ['due_at' => '2026-10-07T09:00:00Z'], authHeaders('developer'))
        ->assertStatus(404);
});

// ---------------------------------------------------------------------------
// Create: optional due_at, normalized to UTC
// ---------------------------------------------------------------------------

it('leaves due_at null when a report is created without one', function () {
    $body = $this->postJson('/redline/api/report', makeReportData(), authHeaders('reporter'))
        ->assertStatus(201)
        ->json();

    expect($body['due_at'])->toBeNull();
});

it('accepts a due date on create', function () {
    $body = $this->postJson('/redline/api/report', makeReportData(['due_at' => '2026-12-01T10:00:00Z']), authHeaders('reporter'))
        ->assertStatus(201)
        ->json();

    expect(Carbon::parse($body['due_at'])->equalTo(Carbon::parse('2026-12-01T10:00:00Z')))->toBeTrue();
});

it('normalizes an offset timestamp to UTC on the wire and in storage', function () {
    $id = createReportForDueAt();

    $body = $this->patchJson("/redline/api/report/{$id}/due_at", ['due_at' => '2026-10-10T14:00:00+02:00'], authHeaders('developer'))
        ->assertStatus(200)
        ->json();

    $expected = Carbon::parse('2026-10-10T12:00:00Z');
    $raw = DB::connection('redline')->table('redline_reports')->where('id', $id)->value('due_at');

    expect(Carbon::parse($body['due_at'])->equalTo($expected))->toBeTrue()
        ->and($raw)->toBe('2026-10-10 12:00:00');
});

it('accepts a due date on a guest submission', function () {
    config(['redline.auth.guest' => true]);

    $body = $this->postJson('/redline/api/report', makeReportData(['due_at' => '2026-12-01T10:00:00Z']))
        ->assertStatus(201)
        ->json();

    expect(Carbon::parse($body['due_at'])->equalTo(Carbon::parse('2026-12-01T10:00:00Z')))->toBeTrue();
});
