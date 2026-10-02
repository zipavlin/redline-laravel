<?php

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Zipavlin\Redline\Models\Report;
use Zipavlin\Redline\Models\User;

beforeEach(function () {
    ensureRedlineDirectories();
});

// ---------------------------------------------------------------------------
// Helpers - names are global across the Pest process, keep them unique.
// ---------------------------------------------------------------------------

/** A report authored by a fresh reporter, plus that author. */
function memberTarget(array $overrides = []): array
{
    static $counter = 0;
    $counter++;

    $author = app(User::class)->create([
        'name' => "Author {$counter}",
        'email' => "member-author{$counter}@example.com",
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

function memberUser(string $name, string $email, string $role = 'developer'): User
{
    return app(User::class)->create(['name' => $name, 'email' => $email, 'role' => $role]);
}

// ---------------------------------------------------------------------------
// POST - additive + idempotent, gated by Operation::UPDATE
// ---------------------------------------------------------------------------

it('lets a developer add a member and returns the member list with initials', function () {
    ['report' => $report] = memberTarget();
    $member = memberUser('Zoe Dev', 'zoe@example.com');

    $body = $this->postJson(
        "/redline/api/report/{$report->id}/member",
        ['user_ids' => [$member->id]],
        authHeaders('developer'),
    )->assertStatus(200)->json();

    expect($body['members'])->toBe([
        ['id' => $member->id, 'name' => 'Zoe Dev', 'initials' => 'ZD'],
    ]);
});

it('is additive and idempotent - re-posting a pair never duplicates it', function () {
    ['report' => $report] = memberTarget();
    $first = memberUser('First Dev', 'first@example.com');
    $second = memberUser('Second Dev', 'second@example.com');
    $headers = authHeaders('developer');
    $url = "/redline/api/report/{$report->id}/member";

    $this->postJson($url, ['user_ids' => [$first->id]], $headers)->assertStatus(200);
    $this->postJson($url, ['user_ids' => [$first->id]], $headers)->assertStatus(200);
    $body = $this->postJson($url, ['user_ids' => [$first->id, $second->id]], $headers)->assertStatus(200)->json();

    expect($body['members'])->toHaveCount(2)
        ->and(DB::connection('redline')->table('redline_members')->where('report_id', $report->id)->count())->toBe(2);
});

it('rejects unknown user ids with 422', function () {
    ['report' => $report] = memberTarget();

    $this->postJson(
        "/redline/api/report/{$report->id}/member",
        ['user_ids' => [Str::uuid()->toString()]],
        authHeaders('developer'),
    )->assertStatus(422);
});

it('rejects a missing or empty user_ids payload with 422', function () {
    ['report' => $report] = memberTarget();
    $headers = authHeaders('developer');
    $url = "/redline/api/report/{$report->id}/member";

    $this->postJson($url, [], $headers)->assertStatus(422);
    $this->postJson($url, ['user_ids' => []], $headers)->assertStatus(422);
    $this->deleteJson($url, [], $headers)->assertStatus(422);
});

it('forbids a non-author reporter from adding a member', function () {
    ['report' => $report] = memberTarget();
    $member = memberUser('Target User', 'target@example.com');

    $this->postJson(
        "/redline/api/report/{$report->id}/member",
        ['user_ids' => [$member->id]],
        authHeaders('reporter'),
    )->assertStatus(403);

    expect(DB::connection('redline')->table('redline_members')->where('report_id', $report->id)->count())->toBe(0);
});

it('forbids a non-author reporter from removing another user', function () {
    ['report' => $report] = memberTarget();
    $member = memberUser('Target User', 'target@example.com');
    $report->members()->attach($member->id);

    $this->deleteJson(
        "/redline/api/report/{$report->id}/member",
        ['user_ids' => [$member->id]],
        authHeaders('reporter'),
    )->assertStatus(403);

    expect(DB::connection('redline')->table('redline_members')->where('report_id', $report->id)->count())->toBe(1);
});

it('lets the report author (a reporter) add a member', function () {
    ['report' => $report, 'author' => $author] = memberTarget();
    $member = memberUser('Assigned Dev', 'assigned@example.com');

    $this->postJson(
        "/redline/api/report/{$report->id}/member",
        ['user_ids' => [$member->id]],
        ['Authorization' => 'Bearer '.makeAuthToken($author->toArray())],
    )->assertStatus(200)->assertJsonCount(1, 'members');
});

// ---------------------------------------------------------------------------
// DELETE - idempotent, returns the remaining list
// ---------------------------------------------------------------------------

it('removes the pair on DELETE and returns the remaining members', function () {
    ['report' => $report] = memberTarget();
    $keep = memberUser('Keep Me', 'keep@example.com');
    $drop = memberUser('Drop Me', 'drop@example.com');
    $headers = authHeaders('developer');
    $url = "/redline/api/report/{$report->id}/member";

    $this->postJson($url, ['user_ids' => [$keep->id, $drop->id]], $headers)->assertStatus(200);

    $body = $this->deleteJson($url, ['user_ids' => [$drop->id]], $headers)->assertStatus(200)->json();

    expect($body['members'])->toHaveCount(1)
        ->and($body['members'][0]['id'])->toBe($keep->id)
        ->and(DB::connection('redline')->table('redline_members')->where('report_id', $report->id)->count())->toBe(1);

    // Removing an already-removed pair is a no-op, not an error.
    $this->deleteJson($url, ['user_ids' => [$drop->id]], $headers)->assertStatus(200);
});

it('lets a non-author reporter remove themselves from the member list', function () {
    ['report' => $report] = memberTarget();
    $reporter = memberUser('Self Remover', 'self-remover@example.com', 'reporter');
    $other = memberUser('Other Dev', 'other-remover@example.com');
    $report->members()->attach([$reporter->id, $other->id]);

    $body = $this->deleteJson(
        "/redline/api/report/{$report->id}/member",
        ['user_ids' => [$reporter->id]],
        ['Authorization' => 'Bearer '.makeAuthToken($reporter->toArray())],
    )->assertStatus(200)->json();

    expect($body['members'])->toHaveCount(1)
        ->and($body['members'][0]['id'])->toBe($other->id)
        ->and(DB::connection('redline')->table('redline_members')->where('user_id', $reporter->id)->count())->toBe(0)
        ->and(DB::connection('redline')->table('redline_members')->where('report_id', $report->id)->count())->toBe(1);
});

it('forbids a non-author reporter from removing themselves together with another user', function () {
    ['report' => $report] = memberTarget();
    $reporter = memberUser('Self Plus Other', 'self-plus@example.com', 'reporter');
    $other = memberUser('Another Dev', 'another-plus@example.com');
    $report->members()->attach([$reporter->id, $other->id]);

    $this->deleteJson(
        "/redline/api/report/{$report->id}/member",
        ['user_ids' => [$reporter->id, $other->id]],
        ['Authorization' => 'Bearer '.makeAuthToken($reporter->toArray())],
    )->assertStatus(403);

    expect(DB::connection('redline')->table('redline_members')->where('report_id', $report->id)->count())->toBe(2);
});

it('treats a non-member reporter removing themselves as an idempotent no-op', function () {
    ['report' => $report] = memberTarget();
    $reporter = memberUser('Not A Member', 'not-member@example.com', 'reporter');
    $other = memberUser('Kept Dev', 'kept-after-noop@example.com');
    $report->members()->attach($other->id);

    $body = $this->deleteJson(
        "/redline/api/report/{$report->id}/member",
        ['user_ids' => [$reporter->id]],
        ['Authorization' => 'Bearer '.makeAuthToken($reporter->toArray())],
    )->assertStatus(200)->json();

    expect($body['members'])->toHaveCount(1)
        ->and($body['members'][0]['id'])->toBe($other->id)
        ->and(DB::connection('redline')->table('redline_members')->where('report_id', $report->id)->count())->toBe(1);
});

it('validates an empty user_ids payload before authorizing a non-author DELETE', function () {
    ['report' => $report] = memberTarget();
    $reporter = memberUser('Empty Payload', 'empty-payload@example.com', 'reporter');

    $this->deleteJson(
        "/redline/api/report/{$report->id}/member",
        ['user_ids' => []],
        ['Authorization' => 'Bearer '.makeAuthToken($reporter->toArray())],
    )->assertStatus(422);
});

it('denies a guest removing themselves from a report', function () {
    ['report' => $report] = memberTarget();
    $guest = memberUser('Guest User', 'guest-self@example.com', 'guest');
    $report->members()->attach($guest->id);

    $this->deleteJson(
        "/redline/api/report/{$report->id}/member",
        ['user_ids' => [$guest->id]],
        ['Authorization' => 'Bearer '.makeAuthToken($guest->toArray())],
    )->assertStatus(403);

    expect(DB::connection('redline')->table('redline_members')->where('report_id', $report->id)->count())->toBe(1);
});

it('denies a pending user removing themselves from a report', function () {
    ['report' => $report] = memberTarget();
    $pending = app(User::class)->create([
        'name' => 'Pending Self Remover',
        'email' => 'pending-self@example.com',
        'role' => 'reporter',
        'status' => 'pending',
    ]);
    $report->members()->attach($pending->id);

    $this->deleteJson(
        "/redline/api/report/{$report->id}/member",
        ['user_ids' => [$pending->id]],
        ['Authorization' => 'Bearer '.makeAuthToken($pending->toArray())],
    )->assertStatus(403);

    expect(DB::connection('redline')->table('redline_members')->where('report_id', $report->id)->count())->toBe(1);
});

it('lets the report author (a reporter) remove another user', function () {
    ['report' => $report, 'author' => $author] = memberTarget();
    $member = memberUser('Assigned Dev', 'author-removes@example.com');
    $report->members()->attach($member->id);

    $body = $this->deleteJson(
        "/redline/api/report/{$report->id}/member",
        ['user_ids' => [$member->id]],
        ['Authorization' => 'Bearer '.makeAuthToken($author->toArray())],
    )->assertStatus(200)->json();

    expect($body['members'])->toBe([])
        ->and(DB::connection('redline')->table('redline_members')->where('report_id', $report->id)->count())->toBe(0);
});

it('collapses a duplicated self id to a single removal for a non-author reporter', function () {
    ['report' => $report] = memberTarget();
    $reporter = memberUser('Dup Self', 'dup-self@example.com', 'reporter');
    $report->members()->attach($reporter->id);

    $body = $this->deleteJson(
        "/redline/api/report/{$report->id}/member",
        ['user_ids' => [$reporter->id, $reporter->id]],
        ['Authorization' => 'Bearer '.makeAuthToken($reporter->toArray())],
    )->assertStatus(200)->json();

    expect($body['members'])->toBe([])
        ->and(DB::connection('redline')->table('redline_members')->where('report_id', $report->id)->count())->toBe(0);
});

// ---------------------------------------------------------------------------
// Read surface - include[members] / :count / :exists
// ---------------------------------------------------------------------------

it('exposes members via include[] sorted by name then id', function () {
    ['report' => $report] = memberTarget();
    $zoe = memberUser('Zoe Zulu', 'zoe@example.com');
    $amy = memberUser('Amy Alpha', 'amy@example.com');
    $headers = authHeaders('developer');

    $this->postJson("/redline/api/report/{$report->id}/member", ['user_ids' => [$zoe->id, $amy->id]], $headers)
        ->assertStatus(200);

    $body = $this->getJson("/redline/api/report/{$report->id}?include[]=members", $headers)
        ->assertStatus(200)->json();

    expect($body['members'])->toHaveCount(2)
        ->and(array_column($body['members'], 'name'))->toBe(['Amy Alpha', 'Zoe Zulu'])
        ->and($body['members'][0])->toBe(['id' => $amy->id, 'name' => 'Amy Alpha', 'initials' => 'AA']);
});

it('serializes members:count/exists as target-named members_count/members_exists', function () {
    ['report' => $report] = memberTarget();
    $member = memberUser('Counted Dev', 'counted@example.com');
    $headers = authHeaders('developer');
    $url = "/redline/api/report/{$report->id}?include[]=members:count&include[]=members:exists";

    $empty = $this->getJson($url, $headers)->assertStatus(200)->json();

    expect($empty['members_count'])->toBe(0)
        ->and($empty['members_exists'])->toBeFalse()
        ->and($empty)->not->toHaveKey('assignments_count')
        ->and($empty)->not->toHaveKey('assignments_exists');

    $report->members()->attach($member->id);

    $filled = $this->getJson($url, $headers)->assertStatus(200)->json();

    expect($filled['members_count'])->toBe(1)
        ->and($filled['members_exists'])->toBeTrue();
});

// ---------------------------------------------------------------------------
// Read surface - filter[member]
// ---------------------------------------------------------------------------

it('filters reports by member and by member:in', function () {
    $member = makeUser('developer');
    $other = makeUser('developer');
    $dev = authHeaders('developer');

    $first = app(Report::class)->create(['url' => 'https://x/a', 'location' => '/a', 'body' => 'a', 'status' => 'new']);
    $second = app(Report::class)->create(['url' => 'https://x/b', 'location' => '/b', 'body' => 'b', 'status' => 'new']);

    $first->members()->attach($member['id']);
    $second->members()->attach($other['id']);

    $single = $this->getJson('/redline/api/report?filter[member]='.$member['id'], $dev)
        ->assertStatus(200)->json('data');
    $both = $this->getJson('/redline/api/report?filter[member:in]='.$member['id'].','.$other['id'], $dev)
        ->assertStatus(200)->json('data');

    expect($single)->toHaveCount(1)
        ->and($single[0]['id'])->toBe($first->id)
        ->and($both)->toHaveCount(2);
});

it('rejects filter[member.name:like] with 422 - no nested delegation through the pivot', function () {
    $this->getJson('/redline/api/report?filter[member.name:like]=Zoe', authHeaders('developer'))
        ->assertStatus(422);
});

it('rejects filter[member.name:eq] with 422 - no silent attribute drop', function () {
    $this->getJson('/redline/api/report?filter[member.name:eq]=Zoe', authHeaders('developer'))
        ->assertStatus(422);
});

it('supports member not/not_in/exists operators', function () {
    $member = makeUser('developer');
    $other = makeUser('developer');
    $dev = authHeaders('developer');

    $first = app(Report::class)->create(['url' => 'https://x/a', 'location' => '/a', 'body' => 'a', 'status' => 'new']);
    $second = app(Report::class)->create(['url' => 'https://x/b', 'location' => '/b', 'body' => 'b', 'status' => 'new']);

    $first->members()->attach($member['id']);
    $second->members()->attach($other['id']);

    $not = $this->getJson('/redline/api/report?filter[member:not]='.$member['id'], $dev)
        ->assertStatus(200)->json('data');
    $notIn = $this->getJson('/redline/api/report?filter[member:not_in]='.$member['id'].','.$other['id'], $dev)
        ->assertStatus(200)->json('data');
    $hasSome = $this->getJson('/redline/api/report?filter[member:exists]=1', $dev)
        ->assertStatus(200)->json('data');
    $hasNone = $this->getJson('/redline/api/report?filter[member:exists]=0', $dev)
        ->assertStatus(200)->json('data');

    expect($not)->toHaveCount(1)
        ->and($not[0]['id'])->toBe($second->id)
        ->and($notIn)->toBe([])
        ->and($hasSome)->toHaveCount(2)
        ->and($hasNone)->toBe([]);
});

it('treats filter[member:not] as negation of the whole membership predicate', function () {
    $first = makeUser('developer');
    $second = makeUser('developer');
    $dev = authHeaders('developer');

    $report = app(Report::class)->create(['url' => 'https://x/a', 'location' => '/a', 'body' => 'a', 'status' => 'new']);
    $report->members()->attach([$first['id'], $second['id']]);

    // The report has both members, so it must NOT match "does not have $first" -
    // an inner-column != would wrongly match because the second row differs.
    $result = $this->getJson('/redline/api/report?filter[member:not]='.$first['id'], $dev)
        ->assertStatus(200)->json('data');

    expect($result)->toBe([]);
});

// ---------------------------------------------------------------------------
// Cleanup - FK cascade on both parent deletions
// ---------------------------------------------------------------------------

it('removes a report members when the report is deleted', function () {
    ['report' => $report] = memberTarget();
    $member = memberUser('Assigned Dev', 'report-cleanup@example.com');
    $report->members()->attach($member->id);

    $this->deleteJson("/redline/api/report/{$report->id}", [], authHeaders('admin'))->assertStatus(204);

    expect(DB::connection('redline')->table('redline_members')->where('report_id', $report->id)->count())->toBe(0);
});

it('removes a user members when the user is deleted', function () {
    ['report' => $report] = memberTarget();
    $member = memberUser('Assigned Dev', 'user-cleanup@example.com');
    $report->members()->attach($member->id);

    $this->deleteJson("/redline/api/user/{$member->id}", [], authHeaders('admin'))->assertStatus(204);

    expect(DB::connection('redline')->table('redline_members')->where('user_id', $member->id)->count())->toBe(0)
        ->and(DB::connection('redline')->table('redline_members')->where('report_id', $report->id)->count())->toBe(0);
});

// ---------------------------------------------------------------------------
// Review follow-ups - strict filters, membership eligibility, operator coverage
// ---------------------------------------------------------------------------

it('refuses to add a pending user with 422', function () {
    ['report' => $report] = memberTarget();
    $pending = app(User::class)->create([
        'name' => 'Pending Dev',
        'email' => 'pending-member@example.com',
        'role' => 'developer',
        'status' => 'pending',
    ]);

    $this->postJson(
        "/redline/api/report/{$report->id}/member",
        ['user_ids' => [$pending->id]],
        authHeaders('developer'),
    )->assertStatus(422);

    expect(DB::connection('redline')->table('redline_members')->where('user_id', $pending->id)->count())->toBe(0);
});

it('treats a DELETE of an unknown user id as a no-op', function () {
    ['report' => $report] = memberTarget();
    $kept = memberUser('Kept Dev', 'kept@example.com');
    $headers = authHeaders('developer');
    $url = "/redline/api/report/{$report->id}/member";

    $this->postJson($url, ['user_ids' => [$kept->id]], $headers)->assertStatus(200);

    $body = $this->deleteJson($url, ['user_ids' => [Str::uuid()->toString()]], $headers)
        ->assertStatus(200)->json();

    expect($body['members'])->toHaveCount(1)
        ->and($body['members'][0]['id'])->toBe($kept->id)
        ->and(DB::connection('redline')->table('redline_members')->where('report_id', $report->id)->count())->toBe(1);
});

it('collapses a duplicated id in one payload to a single member', function () {
    ['report' => $report] = memberTarget();
    $member = memberUser('Dup Dev', 'dup@example.com');

    $body = $this->postJson(
        "/redline/api/report/{$report->id}/member",
        ['user_ids' => [$member->id, $member->id]],
        authHeaders('developer'),
    )->assertStatus(200)->json();

    expect($body['members'])->toHaveCount(1)
        ->and(DB::connection('redline')->table('redline_members')->where('report_id', $report->id)->count())->toBe(1);
});

it('lets an admin add a member', function () {
    ['report' => $report] = memberTarget();
    $member = memberUser('Admin Assigned', 'admin-assigned@example.com');

    $this->postJson(
        "/redline/api/report/{$report->id}/member",
        ['user_ids' => [$member->id]],
        authHeaders('admin'),
    )->assertStatus(200)->assertJsonCount(1, 'members');
});
