<?php

use Zipavlin\Redline\Http\Query\ResourceQuery;
use Zipavlin\Redline\Models\Base\Report;
use Zipavlin\Redline\Models\Base\User;

beforeEach(function () {
    ensureRedlineDirectories();
    $this->devHeaders = authHeaders('developer');
});

// ---------------------------------------------------------------------------
// splitKey() / splitIncludeKey() - the first unit tests in this layer. Both
// are private, so invoked via reflection; this is the only place bugs 2, 3,
// and 6 (the wrong-destructure/strict-compare/dead-code bugs fixed in Steps
// 0-2) are exercised without booting a full HTTP request against a DB.
// ---------------------------------------------------------------------------

function invokeSplitKey(string $key): array
{
    $method = new ReflectionMethod(ResourceQuery::class, 'splitKey');
    $method->setAccessible(true);

    return $method->invoke(null, $key);
}

function invokeSplitIncludeKey(string $key): array
{
    $method = new ReflectionMethod(ResourceQuery::class, 'splitIncludeKey');
    $method->setAccessible(true);

    return $method->invoke(null, $key);
}

it('splitKey: bare target defaults to eq operator and id attribute', function () {
    expect(invokeSplitKey('status'))->toBe(['status', 'id', 'eq']);
});

it('splitKey: target:operator', function () {
    expect(invokeSplitKey('status:in'))->toBe(['status', 'id', 'in']);
});

it('splitKey: bare relation shorthand == target.id', function () {
    expect(invokeSplitKey('author'))->toBe(invokeSplitKey('author.id'));
});

it('splitKey: target.attribute', function () {
    expect(invokeSplitKey('author.id'))->toBe(['author', 'id', 'eq']);
});

it('splitKey: target.attribute:operator', function () {
    expect(invokeSplitKey('author.id:in'))->toBe(['author', 'id', 'in']);
});

it('splitKey: relation.attribute for a dotted include-style key', function () {
    expect(invokeSplitKey('screenshot.url'))->toBe(['screenshot', 'url', 'eq']);
});

it('splitIncludeKey: bare token has no operator or attribute (unlike splitKey, which defaults to eq/id)', function () {
    expect(invokeSplitIncludeKey('title'))->toBe(['title', null, null]);
});

it('splitIncludeKey: target:operator projection', function () {
    expect(invokeSplitIncludeKey('comments:count'))->toBe(['comments', null, 'count']);
});

it('splitIncludeKey: target.attribute shorthand', function () {
    expect(invokeSplitIncludeKey('author.initials'))->toBe(['author', 'initials', null]);
});

// ---------------------------------------------------------------------------
// Collect-all-errors - the grammar's central invariant: every violation in
// one request is reported together, never just the first one hit.
// ---------------------------------------------------------------------------

it('reports every filter violation in one request, not just the first', function () {
    $response = $this->getJson('/redline/api/report?filter[nope]=1&filter[status:regex]=x&sort[body]=desc', $this->devHeaders)
        ->assertStatus(422)
        ->json();

    expect($response['errors'])->toHaveKeys(['filter.nope', 'filter.status:regex', 'sort.body']);
});

// ---------------------------------------------------------------------------
// paginate[] boundaries
// ---------------------------------------------------------------------------

it('accepts per_page at the boundaries (1 and 100)', function () {
    $this->getJson('/redline/api/report?paginate[per_page]=1', $this->devHeaders)->assertStatus(200);
    $this->getJson('/redline/api/report?paginate[per_page]=100', $this->devHeaders)->assertStatus(200);
});

it('rejects per_page just outside the boundaries (0 and 101)', function () {
    $this->getJson('/redline/api/report?paginate[per_page]=0', $this->devHeaders)->assertStatus(422);
    $this->getJson('/redline/api/report?paginate[per_page]=101', $this->devHeaders)->assertStatus(422);
});

it('rejects a negative per_page', function () {
    $this->getJson('/redline/api/report?paginate[per_page]=-1', $this->devHeaders)->assertStatus(422);
});

it('rejects a non-numeric per_page rather than silently casting it to 0 (which paginate() would then choke on)', function () {
    $this->getJson('/redline/api/report?paginate[per_page]=abc', $this->devHeaders)->assertStatus(422);
});

it('rejects an unknown paginate option', function () {
    $this->getJson('/redline/api/report?paginate[bogus]=1', $this->devHeaders)->assertStatus(422);
});

// ---------------------------------------------------------------------------
// use() narrowing - an excluded-but-present group 422s, it is never silently
// dropped (show() only accepts INCLUDE; filter[]/sort[]/paginate[] on it are
// all rejections, not no-ops).
// ---------------------------------------------------------------------------

it('rejects filter[] sent to show(), which only accepts include[]', function () {
    $user = app(User::class)->firstOrCreate(['email' => 'parse-test@example.com'], ['name' => 'P', 'role' => 'reporter']);
    $report = app(Report::class)->create([
        'author_id' => $user->id, 'url' => 'https://x/y', 'location' => '/y', 'body' => 'a', 'status' => 'new',
    ]);

    $this->getJson("/redline/api/report/{$report->id}?filter[status]=new", $this->devHeaders)
        ->assertStatus(422);
});

it('rejects sort[] and paginate[] sent to show() the same way', function () {
    $user = app(User::class)->firstOrCreate(['email' => 'parse-test2@example.com'], ['name' => 'P2', 'role' => 'reporter']);
    $report = app(Report::class)->create([
        'author_id' => $user->id, 'url' => 'https://x/y', 'location' => '/y', 'body' => 'a', 'status' => 'new',
    ]);

    $this->getJson("/redline/api/report/{$report->id}?sort[created_at]=desc", $this->devHeaders)->assertStatus(422);
    $this->getJson("/redline/api/report/{$report->id}?paginate[per_page]=5", $this->devHeaders)->assertStatus(422);
});
