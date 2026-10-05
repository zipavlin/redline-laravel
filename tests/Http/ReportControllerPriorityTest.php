<?php

use Zipavlin\Redline\Models\Report;
use Zipavlin\Redline\Models\User;

beforeEach(function () {
    ensureRedlineDirectories();
});

// ---------------------------------------------------------------------------
// Helpers
// ---------------------------------------------------------------------------

/**
 * Create a report, optionally at an explicit priority, owned by a shared
 * developer (owner identity is irrelevant for developer/admin callers).
 */
function createReportForPriority(?string $priority = null, ?string $userId = null): string
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

    if ($priority !== null) {
        $attributes['priority'] = $priority;
    }

    return app(Report::class)->create($attributes)->id;
}

/**
 * A reporter plus one of their own reports at the given priority.
 *
 * @return array{0: string, 1: array<string, string>} [reportId, authHeaders]
 */
function reporterOwningPriorityReport(?string $priority = null): array
{
    $user = makeUser('reporter');
    $id = createReportForPriority($priority, $user['id']);

    return [$id, ['Authorization' => 'Bearer '.makeAuthToken($user)]];
}

// ---------------------------------------------------------------------------
// Setting priority
// ---------------------------------------------------------------------------

it('lets a developer set each of the four priority levels', function (string $priority) {
    $id = createReportForPriority();

    $this->patchJson("/redline/api/report/{$id}/priority", ['priority' => $priority], authHeaders('developer'))
        ->assertStatus(200)
        ->assertJsonPath('priority', $priority);

    expect(app(Report::class)->find($id)->priority)->toBe($priority);
})->with(['low', 'normal', 'high', 'critical']);

it('lets an admin set priority', function () {
    $id = createReportForPriority();

    $this->patchJson("/redline/api/report/{$id}/priority", ['priority' => 'critical'], authHeaders('admin'))
        ->assertStatus(200)
        ->assertJsonPath('priority', 'critical');
});

it('lets a report owner set their own report priority to any value', function () {
    [$id, $headers] = reporterOwningPriorityReport('low');

    $this->patchJson("/redline/api/report/{$id}/priority", ['priority' => 'critical'], $headers)
        ->assertStatus(200)
        ->assertJsonPath('priority', 'critical');
});

it('returns the fresh row without disturbing other fields', function () {
    $id = createReportForPriority('normal');
    $before = app(Report::class)->find($id);

    $body = $this->patchJson("/redline/api/report/{$id}/priority", ['priority' => 'high'], authHeaders('developer'))
        ->assertStatus(200)
        ->json();

    expect($body['priority'])->toBe('high')
        ->and($body['status'])->toBe($before->status)
        ->and($body['id'])->toBe($id);
});

// ---------------------------------------------------------------------------
// Authorization
// ---------------------------------------------------------------------------

it('forbids a reporter from changing a report they do not own', function () {
    $id = createReportForPriority('normal'); // owned by dev@example.com

    $this->patchJson("/redline/api/report/{$id}/priority", ['priority' => 'critical'], authHeaders('reporter'))
        ->assertStatus(403);
});

// ---------------------------------------------------------------------------
// Validation / not found
// ---------------------------------------------------------------------------

it('returns 422 when priority is missing', function () {
    $id = createReportForPriority();

    $this->patchJson("/redline/api/report/{$id}/priority", [], authHeaders('developer'))
        ->assertStatus(422)
        ->assertJsonValidationErrors(['priority']);
});

it('returns 422 when priority is not a valid value', function () {
    $id = createReportForPriority();

    $this->patchJson("/redline/api/report/{$id}/priority", ['priority' => 'urgent'], authHeaders('developer'))
        ->assertStatus(422)
        ->assertJsonValidationErrors(['priority']);
});

it('returns 404 when the report ID does not exist', function () {
    $this->patchJson('/redline/api/report/NONEXISTENT_ID/priority', ['priority' => 'high'], authHeaders('developer'))
        ->assertStatus(404);
});

// ---------------------------------------------------------------------------
// Defaults on create
// ---------------------------------------------------------------------------

it('defaults priority to normal when a report is created without one', function () {
    $body = $this->postJson('/redline/api/report', makeReportData(), authHeaders('reporter'))
        ->assertStatus(201)
        ->json();

    expect($body['priority'])->toBe('normal');
});

it('accepts an explicit priority on create', function () {
    $body = $this->postJson('/redline/api/report', makeReportData(['priority' => 'critical']), authHeaders('reporter'))
        ->assertStatus(201)
        ->json();

    expect($body['priority'])->toBe('critical');
});

it('returns 422 when creating with an unknown priority', function () {
    $this->postJson('/redline/api/report', makeReportData(['priority' => 'urgent']), authHeaders('reporter'))
        ->assertStatus(422)
        ->assertJsonValidationErrors(['priority']);
});

it('accepts an explicit priority on a guest submission', function () {
    config(['redline.auth.guest' => true]);

    $body = $this->postJson('/redline/api/report', makeReportData(['priority' => 'high']))
        ->assertStatus(201)
        ->json();

    expect($body['priority'])->toBe('high');
});
