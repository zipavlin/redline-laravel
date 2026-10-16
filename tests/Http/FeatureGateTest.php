<?php

use Zipavlin\Redline\Models\Report;
use Zipavlin\Redline\Models\User;

beforeEach(function () {
    ensureRedlineDirectories();
});

// ---------------------------------------------------------------------------
// Helper - create a report to attach feature-gated actions to.
// ---------------------------------------------------------------------------

function createReportForFeatureGate(): array
{
    $user = app(User::class)->firstOrCreate(
        ['email' => 'gate-reporter@example.com'],
        ['name' => 'Gate Reporter', 'role' => 'reporter']
    );

    $report = app(Report::class)->create([
        'author_id' => $user->id,
        'url' => 'https://example.com/',
        'location' => '/',
        'body' => 'Feature gate test report.',
        'status' => 'new',
    ]);

    return $report->toArray();
}

// ---------------------------------------------------------------------------
// Gate on the cheapest enabled/disabled call site: comment store.
// ---------------------------------------------------------------------------

it('answers 403 for an endpoint whose feature is not enabled', function () {
    config(['redline.features' => ['screenshots']]);

    $report = createReportForFeatureGate();

    $this->postJson("/redline/api/report/{$report['id']}/comment", [
        'body' => 'Should not be stored.',
    ], authHeaders('developer'))
        ->assertStatus(403);
});

it('stores the comment when its feature is enabled', function () {
    config(['redline.features' => ['comments']]);

    $report = createReportForFeatureGate();

    $this->postJson("/redline/api/report/{$report['id']}/comment", [
        'body' => 'Stored now.',
    ], authHeaders('developer'))
        ->assertStatus(201)
        ->assertJsonPath('body', 'Stored now.');
});
