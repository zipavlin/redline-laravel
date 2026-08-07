<?php

use Zipavlin\Redline\Models\Base\Report;
use Zipavlin\Redline\Models\Base\Screenshot;
use Zipavlin\Redline\Models\Base\User;

beforeEach(function () {
    ensureRedlineDirectories();
    $this->devHeaders = authHeaders('developer');
});

function includeGrammarReport(array $overrides = []): array
{
    $user = app(User::class)->firstOrCreate(
        ['email' => 'grammar@example.com'],
        ['name' => 'Grammar Test', 'role' => 'reporter'],
    );

    $report = app(Report::class)->create(array_merge([
        'author_id' => $user->id,
        'url' => 'https://x/y',
        'location' => '/y',
        'body' => 'Body.',
        'status' => 'new',
    ], $overrides));

    return ['id' => $report->id, 'user' => $user];
}

// ---------------------------------------------------------------------------
// Owner-key / needs auto-injection (silent-null and 500 regressions)
// ---------------------------------------------------------------------------

it('include[author]=name still returns a non-null author - the owner key is auto-selected', function () {
    $report = includeGrammarReport();

    $body = $this->getJson("/redline/api/report/{$report['id']}?include[author]=name", $this->devHeaders)
        ->assertStatus(200)
        ->json();

    expect($body['author'])->not->toBeNull()
        ->and($body['author']['name'])->toBe('Grammar Test');
});

it('include[screenshot]=url returns a working URL even when only url was requested', function () {
    $report = includeGrammarReport();
    app(Screenshot::class)->create(['report_id' => $report['id'], 'path' => 'x.png']);

    $body = $this->getJson("/redline/api/report/{$report['id']}?include[screenshot]=url", $this->devHeaders)
        ->assertStatus(200)
        ->json();

    expect($body['screenshot']['url'])->toBeString()->toContain('/screenshot/');
});

it('rejects include[author]=initials with 422 - initials are no longer generated server-side', function () {
    $report = includeGrammarReport();

    $this->getJson("/redline/api/report/{$report['id']}?include[author]=initials", $this->devHeaders)
        ->assertStatus(422);
});

// ---------------------------------------------------------------------------
// Wire-shape parsing edge cases
// ---------------------------------------------------------------------------

it('mixes list-form and map-form include[] in one request', function () {
    $report = includeGrammarReport();

    $body = $this->getJson("/redline/api/report/{$report['id']}?include[]=pin&include[author]=id,name", $this->devHeaders)
        ->assertStatus(200)
        ->json();

    expect($body)->toHaveKeys(['pin', 'author'])
        ->and($body['author'])->toHaveKeys(['id', 'name']);
});

it('accepts the array field-list form include[author][]=id&include[author][]=name', function () {
    $report = includeGrammarReport();

    $body = $this->getJson("/redline/api/report/{$report['id']}?include[author][]=id&include[author][]=name", $this->devHeaders)
        ->assertStatus(200)
        ->json();

    expect($body['author'])->toHaveKeys(['id', 'name'])
        ->and($body['author'])->not->toHaveKey('initials');
});

it('merges two spellings of the same target - include[author]=id and include[author.email]', function () {
    $report = includeGrammarReport();

    $body = $this->getJson("/redline/api/report/{$report['id']}?include[author]=id&include[]=author.email", $this->devHeaders)
        ->assertStatus(200)
        ->json();

    expect($body['author'])->toHaveKeys(['id', 'email']);
});

it('include[pin] with no = means the default bare shape, not an empty field list', function () {
    $report = includeGrammarReport();

    $this->getJson("/redline/api/report/{$report['id']}?include[pin]", $this->devHeaders)
        ->assertStatus(200)
        ->assertJsonStructure(['pin']);
});

it('rejects a nested non-scalar include[] value with 422', function () {
    $report = includeGrammarReport();

    $this->getJson("/redline/api/report/{$report['id']}?include[author][x][y]=1", $this->devHeaders)
        ->assertStatus(422);
});

it('rejects the same target sent both bare and with a field list', function () {
    $report = includeGrammarReport();

    $this->getJson("/redline/api/report/{$report['id']}?include[]=author&include[author]=id,name", $this->devHeaders)
        ->assertStatus(422);
});

// ---------------------------------------------------------------------------
// Attribute allowlist - not $hidden-derived, an explicit surface
// ---------------------------------------------------------------------------

it('rejects include[author]=password with 422 - password is not a declared attribute', function () {
    $report = includeGrammarReport();

    $this->getJson("/redline/api/report/{$report['id']}?include[author]=password", $this->devHeaders)
        ->assertStatus(422);
});
