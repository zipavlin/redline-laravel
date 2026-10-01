<?php

use Illuminate\Support\Str;
use Zipavlin\Redline\Models\Base\Assignment;
use Zipavlin\Redline\Models\Base\Report;
use Zipavlin\Redline\Models\Base\User;

beforeEach(function () {
    ensureRedlineDirectories();
});

// ---------------------------------------------------------------------------
// Helpers - names are global across the Pest process, keep them unique.
// ---------------------------------------------------------------------------

/** A report authored by a fresh reporter, plus that author. */
function assignmentTarget(array $overrides = []): array
{
    static $counter = 0;
    $counter++;

    $author = app(User::class)->create([
        'name' => "Author {$counter}",
        'email' => "assignment-author{$counter}@example.com",
        'role' => 'reporter',
    ]);

    $report = app(Report::class)->create(array_merge([
        'author_id' => $author->id,
        'url' => 'https://staging.example.com/dashboard',
        'location' => '/dashboard',
        'body' => 'Bug report.',
        'status' => 'new',
    ], $overrides));

    return ['report' => $report, 'author' => $author];
}

function assigneeUser(string $name, string $email, string $role = 'developer'): User
{
    return app(User::class)->create(['name' => $name, 'email' => $email, 'role' => $role]);
}

// ---------------------------------------------------------------------------
// POST - additive + idempotent, gated by Operation::UPDATE
// ---------------------------------------------------------------------------

it('lets a developer assign a user and returns the assignee list with initials', function () {
    ['report' => $report] = assignmentTarget();
    $assignee = assigneeUser('Zoe Dev', 'zoe@example.com');

    $body = $this->postJson(
        "/redline/api/report/{$report->id}/assignee",
        ['user_ids' => [$assignee->id]],
        authHeaders('developer'),
    )->assertStatus(200)->json();

    expect($body['assignees'])->toBe([
        ['id' => $assignee->id, 'name' => 'Zoe Dev', 'initials' => 'ZD'],
    ]);
});

it('is additive and idempotent - re-posting a pair never duplicates it', function () {
    ['report' => $report] = assignmentTarget();
    $first = assigneeUser('First Dev', 'first@example.com');
    $second = assigneeUser('Second Dev', 'second@example.com');
    $headers = authHeaders('developer');
    $url = "/redline/api/report/{$report->id}/assignee";

    $this->postJson($url, ['user_ids' => [$first->id]], $headers)->assertStatus(200);
    $this->postJson($url, ['user_ids' => [$first->id]], $headers)->assertStatus(200);
    $body = $this->postJson($url, ['user_ids' => [$first->id, $second->id]], $headers)->assertStatus(200)->json();

    expect($body['assignees'])->toHaveCount(2)
        ->and(app(Assignment::class)->where('report_id', $report->id)->count())->toBe(2);
});

it('rejects unknown user ids with 422', function () {
    ['report' => $report] = assignmentTarget();

    $this->postJson(
        "/redline/api/report/{$report->id}/assignee",
        ['user_ids' => [Str::uuid()->toString()]],
        authHeaders('developer'),
    )->assertStatus(422);
});

it('rejects a missing or empty user_ids payload with 422', function () {
    ['report' => $report] = assignmentTarget();
    $headers = authHeaders('developer');
    $url = "/redline/api/report/{$report->id}/assignee";

    $this->postJson($url, [], $headers)->assertStatus(422);
    $this->postJson($url, ['user_ids' => []], $headers)->assertStatus(422);
    $this->deleteJson($url, [], $headers)->assertStatus(422);
});

it('forbids a non-author reporter from assigning and unassigning', function () {
    ['report' => $report] = assignmentTarget();
    $assignee = assigneeUser('Target User', 'target@example.com');
    $headers = authHeaders('reporter');
    $url = "/redline/api/report/{$report->id}/assignee";

    $this->postJson($url, ['user_ids' => [$assignee->id]], $headers)->assertStatus(403);
    $this->deleteJson($url, ['user_ids' => [$assignee->id]], $headers)->assertStatus(403);
});

it('lets the report author (a reporter) assign', function () {
    ['report' => $report, 'author' => $author] = assignmentTarget();
    $assignee = assigneeUser('Assigned Dev', 'assigned@example.com');

    $this->postJson(
        "/redline/api/report/{$report->id}/assignee",
        ['user_ids' => [$assignee->id]],
        ['Authorization' => 'Bearer '.makeAuthToken($author->toArray())],
    )->assertStatus(200)->assertJsonCount(1, 'assignees');
});

// ---------------------------------------------------------------------------
// DELETE - idempotent, returns the remaining list
// ---------------------------------------------------------------------------

it('removes the pair on DELETE and returns the remaining assignees', function () {
    ['report' => $report] = assignmentTarget();
    $keep = assigneeUser('Keep Me', 'keep@example.com');
    $drop = assigneeUser('Drop Me', 'drop@example.com');
    $headers = authHeaders('developer');
    $url = "/redline/api/report/{$report->id}/assignee";

    $this->postJson($url, ['user_ids' => [$keep->id, $drop->id]], $headers)->assertStatus(200);

    $body = $this->deleteJson($url, ['user_ids' => [$drop->id]], $headers)->assertStatus(200)->json();

    expect($body['assignees'])->toHaveCount(1)
        ->and($body['assignees'][0]['id'])->toBe($keep->id)
        ->and(app(Assignment::class)->where('report_id', $report->id)->count())->toBe(1);

    // Removing an already-removed pair is a no-op, not an error.
    $this->deleteJson($url, ['user_ids' => [$drop->id]], $headers)->assertStatus(200);
});

// ---------------------------------------------------------------------------
// Read surface - include[assignees] / :count / :exists
// ---------------------------------------------------------------------------

it('exposes assignees via include[] sorted by name then id', function () {
    ['report' => $report] = assignmentTarget();
    $zoe = assigneeUser('Zoe Zulu', 'zoe@example.com');
    $amy = assigneeUser('Amy Alpha', 'amy@example.com');
    $headers = authHeaders('developer');

    $this->postJson("/redline/api/report/{$report->id}/assignee", ['user_ids' => [$zoe->id, $amy->id]], $headers)
        ->assertStatus(200);

    $body = $this->getJson("/redline/api/report/{$report->id}?include[]=assignees", $headers)
        ->assertStatus(200)->json();

    expect($body['assignees'])->toHaveCount(2)
        ->and(array_column($body['assignees'], 'name'))->toBe(['Amy Alpha', 'Zoe Zulu'])
        ->and($body['assignees'][0])->toBe(['id' => $amy->id, 'name' => 'Amy Alpha', 'initials' => 'AA']);
});

it('serializes assignees:count/exists as target-named assignees_count/assignees_exists', function () {
    ['report' => $report] = assignmentTarget();
    $assignee = assigneeUser('Counted Dev', 'counted@example.com');
    $headers = authHeaders('developer');
    $url = "/redline/api/report/{$report->id}?include[]=assignees:count&include[]=assignees:exists";

    $empty = $this->getJson($url, $headers)->assertStatus(200)->json();

    expect($empty['assignees_count'])->toBe(0)
        ->and($empty['assignees_exists'])->toBeFalse()
        ->and($empty)->not->toHaveKey('assignments_count')
        ->and($empty)->not->toHaveKey('assignments_exists');

    app(Assignment::class)->create(['report_id' => $report->id, 'user_id' => $assignee->id]);

    $filled = $this->getJson($url, $headers)->assertStatus(200)->json();

    expect($filled['assignees_count'])->toBe(1)
        ->and($filled['assignees_exists'])->toBeTrue();
});

// ---------------------------------------------------------------------------
// Read surface - filter[assigned] / filter[assignee]
// ---------------------------------------------------------------------------

it('filters to the caller with filter[assigned]=1 and the rest with =0', function () {
    $user = makeUser('developer');
    $headers = ['Authorization' => 'Bearer '.makeAuthToken($user)];

    $mine = app(Report::class)->create([
        'author_id' => $user['id'], 'url' => 'https://x/a', 'location' => '/a', 'body' => 'a', 'status' => 'new',
    ]);
    $other = app(Report::class)->create([
        'author_id' => makeUser('reporter')['id'], 'url' => 'https://x/b', 'location' => '/b', 'body' => 'b', 'status' => 'new',
    ]);

    app(Assignment::class)->create(['report_id' => $mine->id, 'user_id' => $user['id']]);

    $assigned = $this->getJson('/redline/api/report?filter[assigned]=1', $headers)->assertStatus(200)->json('data');
    $unassigned = $this->getJson('/redline/api/report?filter[assigned]=0', $headers)->assertStatus(200)->json('data');

    expect($assigned)->toHaveCount(1)
        ->and($assigned[0]['id'])->toBe($mine->id)
        ->and(collect($unassigned)->pluck('id')->all())->toBe([$other->id]);
});

it('filters reports by assignee and by assignee:in', function () {
    $assignee = makeUser('developer');
    $other = makeUser('developer');
    $dev = authHeaders('developer');

    $first = app(Report::class)->create(['url' => 'https://x/a', 'location' => '/a', 'body' => 'a', 'status' => 'new']);
    $second = app(Report::class)->create(['url' => 'https://x/b', 'location' => '/b', 'body' => 'b', 'status' => 'new']);

    app(Assignment::class)->create(['report_id' => $first->id, 'user_id' => $assignee['id']]);
    app(Assignment::class)->create(['report_id' => $second->id, 'user_id' => $other['id']]);

    $single = $this->getJson('/redline/api/report?filter[assignee]='.$assignee['id'], $dev)
        ->assertStatus(200)->json('data');
    $both = $this->getJson('/redline/api/report?filter[assignee:in]='.$assignee['id'].','.$other['id'], $dev)
        ->assertStatus(200)->json('data');

    expect($single)->toHaveCount(1)
        ->and($single[0]['id'])->toBe($first->id)
        ->and($both)->toHaveCount(2);
});

it('rejects filter[assignee.name:like] with 422 - no nested delegation through the junction', function () {
    $this->getJson('/redline/api/report?filter[assignee.name:like]=Zoe', authHeaders('developer'))
        ->assertStatus(422);
});

// ---------------------------------------------------------------------------
// Cleanup - FK cascade on both parent deletions
// ---------------------------------------------------------------------------

it('removes a report assignments when the report is deleted', function () {
    ['report' => $report] = assignmentTarget();
    $assignee = assigneeUser('Assigned Dev', 'report-cleanup@example.com');
    app(Assignment::class)->create(['report_id' => $report->id, 'user_id' => $assignee->id]);

    $this->deleteJson("/redline/api/report/{$report->id}", [], authHeaders('admin'))->assertStatus(204);

    expect(app(Assignment::class)->where('report_id', $report->id)->count())->toBe(0);
});

it('removes a user assignments when the user is deleted', function () {
    ['report' => $report] = assignmentTarget();
    $assignee = assigneeUser('Assigned Dev', 'user-cleanup@example.com');
    app(Assignment::class)->create(['report_id' => $report->id, 'user_id' => $assignee->id]);

    $this->deleteJson("/redline/api/user/{$assignee->id}", [], authHeaders('admin'))->assertStatus(204);

    expect(app(Assignment::class)->where('user_id', $assignee->id)->count())->toBe(0)
        ->and(app(Assignment::class)->where('report_id', $report->id)->count())->toBe(0);
});

// ---------------------------------------------------------------------------
// Review follow-ups - strict filters, assignability, operator coverage
// ---------------------------------------------------------------------------

it('rejects filter[assignee.name:eq] with 422 - no silent attribute drop', function () {
    $this->getJson('/redline/api/report?filter[assignee.name:eq]=Zoe', authHeaders('developer'))
        ->assertStatus(422);
});

it('rejects a non-boolean filter[assigned] value with 422', function () {
    $this->getJson('/redline/api/report?filter[assigned]=banana', authHeaders('developer'))
        ->assertStatus(422);
});

it('refuses to assign a pending user with 422', function () {
    ['report' => $report] = assignmentTarget();
    $pending = app(User::class)->create([
        'name' => 'Pending Dev',
        'email' => 'pending-assignee@example.com',
        'role' => 'developer',
        'status' => 'pending',
    ]);

    $this->postJson(
        "/redline/api/report/{$report->id}/assignee",
        ['user_ids' => [$pending->id]],
        authHeaders('developer'),
    )->assertStatus(422);

    expect(app(Assignment::class)->where('user_id', $pending->id)->count())->toBe(0);
});

it('supports assignee not/not_in/exists operators', function () {
    $assignee = makeUser('developer');
    $other = makeUser('developer');
    $dev = authHeaders('developer');

    $first = app(Report::class)->create(['url' => 'https://x/a', 'location' => '/a', 'body' => 'a', 'status' => 'new']);
    $second = app(Report::class)->create(['url' => 'https://x/b', 'location' => '/b', 'body' => 'b', 'status' => 'new']);

    app(Assignment::class)->create(['report_id' => $first->id, 'user_id' => $assignee['id']]);
    app(Assignment::class)->create(['report_id' => $second->id, 'user_id' => $other['id']]);

    $not = $this->getJson('/redline/api/report?filter[assignee:not]='.$assignee['id'], $dev)
        ->assertStatus(200)->json('data');
    $notIn = $this->getJson('/redline/api/report?filter[assignee:not_in]='.$assignee['id'].','.$other['id'], $dev)
        ->assertStatus(200)->json('data');
    $hasSome = $this->getJson('/redline/api/report?filter[assignee:exists]=1', $dev)
        ->assertStatus(200)->json('data');
    $hasNone = $this->getJson('/redline/api/report?filter[assignee:exists]=0', $dev)
        ->assertStatus(200)->json('data');

    expect($not)->toHaveCount(1)
        ->and($not[0]['id'])->toBe($second->id)
        ->and($notIn)->toBe([])
        ->and($hasSome)->toHaveCount(2)
        ->and($hasNone)->toBe([]);
});

it('treats a DELETE of an unknown user id as a no-op', function () {
    ['report' => $report] = assignmentTarget();
    $kept = assigneeUser('Kept Dev', 'kept@example.com');
    $headers = authHeaders('developer');
    $url = "/redline/api/report/{$report->id}/assignee";

    $this->postJson($url, ['user_ids' => [$kept->id]], $headers)->assertStatus(200);

    $body = $this->deleteJson($url, ['user_ids' => [Str::uuid()->toString()]], $headers)
        ->assertStatus(200)->json();

    expect($body['assignees'])->toHaveCount(1)
        ->and($body['assignees'][0]['id'])->toBe($kept->id)
        ->and(app(Assignment::class)->where('report_id', $report->id)->count())->toBe(1);
});

it('collapses a duplicated id in one payload to a single assignment', function () {
    ['report' => $report] = assignmentTarget();
    $assignee = assigneeUser('Dup Dev', 'dup@example.com');

    $body = $this->postJson(
        "/redline/api/report/{$report->id}/assignee",
        ['user_ids' => [$assignee->id, $assignee->id]],
        authHeaders('developer'),
    )->assertStatus(200)->json();

    expect($body['assignees'])->toHaveCount(1)
        ->and(app(Assignment::class)->where('report_id', $report->id)->count())->toBe(1);
});

it('lets an admin assign', function () {
    ['report' => $report] = assignmentTarget();
    $assignee = assigneeUser('Admin Assigned', 'admin-assigned@example.com');

    $this->postJson(
        "/redline/api/report/{$report->id}/assignee",
        ['user_ids' => [$assignee->id]],
        authHeaders('admin'),
    )->assertStatus(200)->assertJsonCount(1, 'assignees');
});
