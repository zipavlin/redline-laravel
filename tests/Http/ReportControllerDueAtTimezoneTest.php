<?php

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Zipavlin\Redline\Models\Report;
use Zipavlin\Redline\Models\User;

/**
 * The deadline column stores a UTC wall clock with no offset. A host whose
 * app.timezone is not UTC makes Laravel's default date cast read that naive
 * string in the *host* timezone, shifting the instant it serializes. These
 * tests pin the naive-storage -> UTC-instant round trip under Europe/Ljubljana
 * (UTC+1/+2), reproducing a real non-UTC deployment.
 *
 * Simulating that host means both config('app.timezone') AND PHP's default
 * timezone (what LoadConfiguration::configureDate() sets from it at boot).
 */
beforeEach(function () {
    ensureRedlineDirectories();

    $this->originalTimezone = date_default_timezone_get();
    config(['app.timezone' => 'Europe/Ljubljana']);
    date_default_timezone_set('Europe/Ljubljana');
});

afterEach(function () {
    date_default_timezone_set($this->originalTimezone);
});

// ---------------------------------------------------------------------------
// Helpers
// ---------------------------------------------------------------------------

/**
 * Create a report owned by the shared developer, optionally with a deadline
 * given as an RFC3339 instant.
 */
function tzDueAtReport(?string $dueAt = null): string
{
    $user = app(User::class)->firstOrCreate(
        ['email' => 'dev@example.com'],
        ['name' => 'Dev', 'role' => 'developer']
    );

    $attributes = [
        'author_id' => $user->id,
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
 * The raw stored string, bypassing the model cast - the storage contract.
 */
function tzDueAtRaw(string $id): ?string
{
    return DB::connection('redline')->table('redline_reports')->where('id', $id)->value('due_at');
}

// ---------------------------------------------------------------------------
// Read/serialization: the defect
// ---------------------------------------------------------------------------

it('serializes a patched deadline as the same UTC instant it stored under a non-UTC app timezone', function () {
    $id = tzDueAtReport();

    $body = $this->patchJson("/redline/api/report/{$id}/due_at", ['due_at' => '2026-10-10T14:00:00Z'], authHeaders('developer'))
        ->assertStatus(200)
        ->json();

    expect($body['due_at'])->toBe('2026-10-10T14:00:00.000000Z')
        ->and(tzDueAtRaw($id))->toBe('2026-10-10 14:00:00')
        ->and(app(Report::class)->find($id)->due_at->equalTo(Carbon::parse('2026-10-10T14:00:00Z')))->toBeTrue();
});

it('serializes a deadline submitted on create as the same UTC instant it stored under a non-UTC app timezone', function () {
    $body = $this->postJson('/redline/api/report', makeReportData(['due_at' => '2026-10-10T14:00:00Z']), authHeaders('reporter'))
        ->assertStatus(201)
        ->json();

    expect($body['due_at'])->toBe('2026-10-10T14:00:00.000000Z')
        ->and(tzDueAtRaw($body['id']))->toBe('2026-10-10 14:00:00');
});

it('normalizes a non-UTC Carbon assigned directly to the model before formatting it for storage', function () {
    $id = tzDueAtReport();

    $report = app(Report::class)->find($id);
    $report->due_at = Carbon::parse('2026-10-10T16:00:00+02:00'); // same instant as 14:00Z
    $report->save();

    expect(tzDueAtRaw($id))->toBe('2026-10-10 14:00:00')
        ->and($report->fresh()->due_at->equalTo(Carbon::parse('2026-10-10T14:00:00Z')))->toBeTrue();
});

it('treats a naive wall-clock string assigned to the model as UTC, not app time', function () {
    $id = tzDueAtReport();

    $report = app(Report::class)->find($id);
    $report->due_at = '2026-10-10 14:00:00'; // no offset: the column stores UTC
    $report->save();

    expect(tzDueAtRaw($id))->toBe('2026-10-10 14:00:00')
        ->and($report->fresh()->due_at->equalTo(Carbon::parse('2026-10-10T14:00:00Z')))->toBeTrue();
});

// ---------------------------------------------------------------------------
// Filter comparison: regression guards (the resolver already compares in UTC)
// ---------------------------------------------------------------------------

it('matches filter[due]=overdue against the stored UTC instant under a non-UTC app timezone', function () {
    $this->travelTo(Carbon::parse('2026-10-05T12:00:00Z'));

    $overdue = tzDueAtReport('2026-10-05T11:00:00Z');
    tzDueAtReport('2026-10-05T12:00:00Z'); // exactly now -> not overdue
    tzDueAtReport('2026-10-06T09:00:00Z'); // future

    $body = $this->getJson('/redline/api/report?filter[due]=overdue', authHeaders('developer'))
        ->assertStatus(200)
        ->json('data');

    expect($body)->toHaveCount(1)
        ->and($body[0]['id'])->toBe($overdue);
});

it('honours the due_soon 24h boundary against the stored UTC instant under a non-UTC app timezone', function () {
    $this->travelTo(Carbon::parse('2026-10-05T12:00:00Z'));

    $atNow = tzDueAtReport('2026-10-05T12:00:00Z');
    $justInside = tzDueAtReport('2026-10-06T11:59:59Z');
    tzDueAtReport('2026-10-06T12:00:00Z'); // exactly +24h -> excluded
    tzDueAtReport('2026-10-05T11:00:00Z'); // overdue, not due soon

    $body = $this->getJson('/redline/api/report?filter[due]=due_soon', authHeaders('developer'))
        ->assertStatus(200)
        ->json('data');

    expect(collect($body)->pluck('id')->sort()->values()->all())
        ->toBe(collect([$atNow, $justInside])->sort()->values()->all());
});
