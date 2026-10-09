<?php

use Illuminate\Support\Carbon;
use Zipavlin\Redline\Models\Report;
use Zipavlin\Redline\Models\User;

beforeEach(function () {
    ensureRedlineDirectories();
    $this->devHeaders = authHeaders('developer');
});

// ---------------------------------------------------------------------------
// Helper - create a report directly via models.
// ---------------------------------------------------------------------------

function storeReport(array $overrides = []): string
{
    $user = app(User::class)->firstOrCreate(
        ['email' => 'reporter@example.com'],
        ['name' => 'Reporter', 'role' => 'reporter']
    );

    $report = app(Report::class)->create(array_merge([
        'author_id' => $user->id,
        'url' => 'https://staging.example.com/dashboard',
        'location' => '/dashboard',
        'body' => 'Bug report.',
        'status' => 'new',
    ], $overrides));

    return $report->id;
}

// ---------------------------------------------------------------------------
// Happy path - the widget's old ?url_key= mode is now filter[location]=
// ---------------------------------------------------------------------------

it('returns an empty data array when the location filter matches nothing', function () {
    $body = $this->getJson('/redline/api/report?filter[location]=/nonexistent', $this->devHeaders)
        ->assertStatus(200)
        ->json();

    expect($body['data'])->toBe([]);
});

it('returns the {data, meta} envelope unconditionally', function () {
    storeReport(['location' => '/page-a']);
    storeReport(['location' => '/page-b']);

    $body = $this->getJson('/redline/api/report', $this->devHeaders)
        ->assertStatus(200)
        ->json();

    expect($body)->toHaveKeys(['data', 'meta'])
        ->and($body)->not->toHaveKey('counts') // counts moved to /report/aggregate
        ->and($body['data'])->toHaveCount(2)
        ->and($body['meta']['total'])->toBe(2);
});

it('returns every row unpaginated when paginate[per_page] is omitted, meta is just {total}', function () {
    foreach (range(1, 5) as $i) {
        storeReport(['location' => "/page-{$i}"]);
    }

    $body = $this->getJson('/redline/api/report', $this->devHeaders)->assertStatus(200)->json();

    expect($body['data'])->toHaveCount(5)
        ->and($body['meta'])->toBe(['total' => 5]);
});

it('still returns the four-key meta when paginate[per_page] is explicit', function () {
    foreach (range(1, 5) as $i) {
        storeReport(['location' => "/page-{$i}"]);
    }

    $body = $this->getJson('/redline/api/report?paginate[per_page]=2', $this->devHeaders)->assertStatus(200)->json();

    expect($body['data'])->toHaveCount(2)
        ->and($body['meta'])->toHaveKeys(['total', 'per_page', 'current_page', 'last_page'])
        ->and($body['meta']['total'])->toBe(5);
});

it('pages deterministically across rows with an identical created_at, via the id tiebreaker', function () {
    foreach (range(1, 20) as $i) {
        storeReport(['location' => "/page-{$i}"]);
    }

    $page1 = $this->getJson('/redline/api/report?paginate[per_page]=7&paginate[page]=1', $this->devHeaders)->json('data');
    $page2 = $this->getJson('/redline/api/report?paginate[per_page]=7&paginate[page]=2', $this->devHeaders)->json('data');
    $page3 = $this->getJson('/redline/api/report?paginate[per_page]=7&paginate[page]=3', $this->devHeaders)->json('data');

    $ids = collect([...$page1, ...$page2, ...$page3])->pluck('id');

    expect($ids)->toHaveCount(20)
        ->and($ids->unique())->toHaveCount(20);
});

it('filters rows by location', function () {
    storeReport(['location' => '/page-a']);
    storeReport(['location' => '/page-a']);
    storeReport(['location' => '/page-b']);

    $body = $this->getJson('/redline/api/report?filter[location]=/page-a', $this->devHeaders)
        ->assertStatus(200)
        ->json('data');

    expect($body)->toHaveCount(2);
});

it('shapes rows by include[] - base fields plus only what was asked for', function () {
    storeReport(['location' => '/page-a']);

    $body = $this->getJson('/redline/api/report?include[]=pin&include[]=element&include[]=title', $this->devHeaders)
        ->assertStatus(200)
        ->json('data');

    expect($body[0])->toHaveKeys(['id', 'status', 'created_at', 'pin', 'element', 'title'])
        ->and($body[0])->not->toHaveKey('body')
        ->and($body[0])->not->toHaveKey('author')
        ->and($body[0])->not->toHaveKey('url')
        ->and($body[0])->not->toHaveKey('location');
});

it('gates browser on include[browser] by role - developers get it, reporters get silent omission', function () {
    storeReport(['location' => '/page', 'browser' => ['user_agent' => 'ua', 'name' => 'Firefox']]);

    $dev = $this->getJson('/redline/api/report?filter[location]=/page&include[]=browser', authHeaders('developer'))
        ->assertStatus(200)
        ->json('data');
    $reporter = $this->getJson('/redline/api/report?filter[location]=/page&include[]=browser', authHeaders('reporter'))
        ->assertStatus(200)
        ->json('data');

    expect($dev[0]['browser'])->toBe(['name' => 'Firefox'])
        ->and($reporter[0])->not->toHaveKey('browser');
});

it('excludes resolved reports when filter[status]=unresolved', function () {
    $id = storeReport(['location' => '/page']);

    app(Report::class)->find($id)?->update(['status' => 'resolved']);

    $body = $this->getJson('/redline/api/report?filter[location]=/page&filter[status]=unresolved', $this->devHeaders)
        ->assertStatus(200)
        ->json('data');

    expect($body)->toBe([]);
});

it('treats filter[status]=resolved as a superset of filter[status]=done', function () {
    storeReport(['location' => '/page', 'status' => 'resolved']);
    storeReport(['location' => '/page', 'status' => 'done']);
    storeReport(['location' => '/page', 'status' => 'new']);

    $resolvedGroup = $this->getJson('/redline/api/report?filter[location]=/page&filter[status]=resolved', $this->devHeaders)->json('data');
    $doneOnly = $this->getJson('/redline/api/report?filter[location]=/page&filter[status]=done', $this->devHeaders)->json('data');

    expect($resolvedGroup)->toHaveCount(2)
        ->and($doneOnly)->toHaveCount(1)
        ->and($doneOnly[0]['status'])->toBe('done');
});

// ---------------------------------------------------------------------------
// Priority - concrete enum values, base-row field, enum-ordered sort
// ---------------------------------------------------------------------------

it('carries priority in the base row without being asked for via include[]', function () {
    storeReport(['location' => '/page', 'priority' => 'critical']);

    $body = $this->getJson('/redline/api/report?filter[location]=/page', $this->devHeaders)
        ->assertStatus(200)
        ->json('data');

    expect($body[0])->toHaveKeys(['id', 'status', 'priority', 'created_at'])
        ->and($body[0]['priority'])->toBe('critical');
});

it('filters reports by a concrete priority value', function () {
    storeReport(['location' => '/page', 'priority' => 'low']);
    storeReport(['location' => '/page', 'priority' => 'high']);
    storeReport(['location' => '/page', 'priority' => 'critical']);

    $body = $this->getJson('/redline/api/report?filter[priority]=high', $this->devHeaders)
        ->assertStatus(200)
        ->json('data');

    expect($body)->toHaveCount(1)
        ->and($body[0]['priority'])->toBe('high');
});

it('filters reports by priority:in (comma-separated OR)', function () {
    storeReport(['location' => '/page', 'priority' => 'low']);
    storeReport(['location' => '/page', 'priority' => 'normal']);
    storeReport(['location' => '/page', 'priority' => 'critical']);

    $body = $this->getJson('/redline/api/report?filter[priority:in]=low,critical', $this->devHeaders)
        ->assertStatus(200)
        ->json('data');

    expect(collect($body)->pluck('priority')->sort()->values()->all())->toBe(['critical', 'low']);
});

it('filters reports by priority:not (excludes the value)', function () {
    storeReport(['location' => '/page', 'priority' => 'low']);
    storeReport(['location' => '/page', 'priority' => 'high']);
    storeReport(['location' => '/page', 'priority' => 'critical']);

    $body = $this->getJson('/redline/api/report?filter[priority:not]=low', $this->devHeaders)
        ->assertStatus(200)
        ->json('data');

    expect(collect($body)->pluck('priority')->sort()->values()->all())->toBe(['critical', 'high']);
});

it('rejects an unknown concrete priority value with 422', function () {
    $this->getJson('/redline/api/report?filter[priority]=urgent', $this->devHeaders)
        ->assertStatus(422)
        ->assertJsonValidationErrors(['filter.priority']);
});

it('rejects an unknown priority inside a priority:in list with 422', function () {
    $this->getJson('/redline/api/report?filter[priority:in]=low,urgent', $this->devHeaders)
        ->assertStatus(422)
        ->assertJsonValidationErrors(['filter.priority']);
});

it('counts reports per priority via aggregate[priority:count]', function () {
    storeReport(['location' => '/page', 'priority' => 'low']);
    storeReport(['location' => '/page', 'priority' => 'low']);
    storeReport(['location' => '/page', 'priority' => 'critical']);

    $body = $this->getJson('/redline/api/report/aggregate?aggregate[priority:count]', $this->devHeaders)
        ->assertStatus(200)
        ->json();

    expect($body['priority']['count'])->toEqual(['low' => 2, 'critical' => 1]);
});

it('sorts by priority in enum order, not lexically', function () {
    storeReport(['location' => '/page', 'priority' => 'normal']);
    storeReport(['location' => '/page', 'priority' => 'critical']);
    storeReport(['location' => '/page', 'priority' => 'low']);
    storeReport(['location' => '/page', 'priority' => 'high']);

    $desc = $this->getJson('/redline/api/report?filter[location]=/page&sort[priority]=desc', $this->devHeaders)
        ->assertStatus(200)
        ->json('data');

    // A lexical sort would yield critical, high, low, normal - this must not.
    expect(collect($desc)->pluck('priority')->all())->toBe(['critical', 'high', 'normal', 'low']);

    $asc = $this->getJson('/redline/api/report?filter[location]=/page&sort[priority]=asc', $this->devHeaders)
        ->assertStatus(200)
        ->json('data');

    expect(collect($asc)->pluck('priority')->all())->toBe(['low', 'normal', 'high', 'critical']);
});

it('defaults to newest-first when no sort is given', function () {
    $firstId = storeReport(['location' => '/page']);
    $this->travel(5)->seconds();
    $secondId = storeReport(['location' => '/page']);

    $body = $this->getJson('/redline/api/report?filter[location]=/page', $this->devHeaders)->json('data');

    expect($body[0]['id'])->toBe($secondId)
        ->and($body[1]['id'])->toBe($firstId);
});

it('sorts by an explicit sort[] key and direction', function () {
    $firstId = storeReport(['location' => '/page']);
    $this->travel(5)->seconds();
    $secondId = storeReport(['location' => '/page']);

    $body = $this->getJson('/redline/api/report?filter[location]=/page&sort[created_at]=asc', $this->devHeaders)->json('data');

    expect($body[0]['id'])->toBe($firstId)
        ->and($body[1]['id'])->toBe($secondId);
});

it('filters authors with the bare relationship shorthand and with author.id', function () {
    $mine = makeUser('developer');
    $other = makeUser('reporter');

    app(Report::class)->create([
        'author_id' => $mine['id'], 'url' => 'https://x/y', 'location' => '/y', 'body' => 'a', 'status' => 'new',
    ]);
    app(Report::class)->create([
        'author_id' => $other['id'], 'url' => 'https://x/y', 'location' => '/y', 'body' => 'b', 'status' => 'new',
    ]);

    $bareShorthand = $this->getJson('/redline/api/report?filter[author]='.$mine['id'], $this->devHeaders)->json('data');
    $explicit = $this->getJson('/redline/api/report?filter[author.id]='.$mine['id'], $this->devHeaders)->json('data');

    expect($bareShorthand)->toHaveCount(1)
        ->and($explicit)->toHaveCount(1)
        ->and($bareShorthand[0]['id'])->toBe($explicit[0]['id']);
});

it('filters authors with author.id:in (comma-separated OR)', function () {
    $mine = makeUser('developer');
    $other = makeUser('reporter');
    $third = makeUser('reporter');

    app(Report::class)->create([
        'author_id' => $mine['id'], 'url' => 'https://x/y', 'location' => '/y', 'body' => 'a', 'status' => 'new',
    ]);
    app(Report::class)->create([
        'author_id' => $other['id'], 'url' => 'https://x/y', 'location' => '/y', 'body' => 'b', 'status' => 'new',
    ]);
    app(Report::class)->create([
        'author_id' => $third['id'], 'url' => 'https://x/y', 'location' => '/y', 'body' => 'c', 'status' => 'new',
    ]);

    $body = $this->getJson('/redline/api/report?filter[author.id:in]='.$mine['id'].','.$other['id'].'&include[]=author', $this->devHeaders)
        ->assertStatus(200)
        ->json('data');

    expect($body)->toHaveCount(2)
        ->and(collect($body)->pluck('author.id')->sort()->values()->all())
        ->toBe(collect([$mine['id'], $other['id']])->sort()->values()->all());
});

it('filters authors by name via delegation to User::querySchema()', function () {
    $match = makeUser('developer');
    app(User::class)->find($match['id'])->update(['name' => 'Zig Zag']);
    $other = makeUser('reporter');

    app(Report::class)->create([
        'author_id' => $match['id'], 'url' => 'https://x/y', 'location' => '/y', 'body' => 'a', 'status' => 'new',
    ]);
    app(Report::class)->create([
        'author_id' => $other['id'], 'url' => 'https://x/y', 'location' => '/y', 'body' => 'b', 'status' => 'new',
    ]);

    $body = $this->getJson('/redline/api/report?filter[author.name:like]=Zig', $this->devHeaders)
        ->assertStatus(200)
        ->json('data');

    expect($body)->toHaveCount(1);
});

it('rejects filter[author.email:like] with 422 - a relation-attribute filter is a boolean oracle, so only attributes User::querySchema() declares are reachable', function () {
    $this->getJson('/redline/api/report?filter[author.email:like]=%40example.com', $this->devHeaders)
        ->assertStatus(422);
});

it('rejects an unknown relation attribute with 422', function () {
    $this->getJson('/redline/api/report?filter[author.bogus:eq]=x', $this->devHeaders)
        ->assertStatus(422);
});

it('filters to the authenticated user with filter[mine]=1', function () {
    $mine = makeUser('developer');
    $other = makeUser('reporter');
    app(Report::class)->create(['author_id' => $mine['id'], 'url' => 'https://x/y', 'location' => '/y', 'body' => 'a', 'status' => 'new']);
    app(Report::class)->create(['author_id' => $other['id'], 'url' => 'https://x/y', 'location' => '/y', 'body' => 'b', 'status' => 'new']);

    $headers = ['Authorization' => 'Bearer '.makeAuthToken($mine)];

    $body = $this->getJson('/redline/api/report?filter[mine]=1', $headers)->json('data');

    expect($body)->toHaveCount(1);
});

it('searches over body and location with the OR-combined search field', function () {
    $user = makeUser('reporter');
    app(Report::class)->create(['author_id' => $user['id'], 'url' => 'https://x/a', 'location' => '/a', 'body' => 'The login button is broken', 'status' => 'new']);
    app(Report::class)->create(['author_id' => $user['id'], 'url' => 'https://x/checkout', 'location' => '/checkout', 'body' => 'Something else', 'status' => 'new']);

    $byBody = $this->getJson('/redline/api/report?filter[search:like]=login', $this->devHeaders)->json('data');
    $byKey = $this->getJson('/redline/api/report?filter[search:like]=checkout', $this->devHeaders)->json('data');

    expect($byBody)->toHaveCount(1)
        ->and($byKey)->toHaveCount(1);
});

it('rejects an unknown filter target with 422', function () {
    $this->getJson('/redline/api/report?filter[nope]=1', $this->devHeaders)->assertStatus(422);
});

it('rejects a disallowed operator on a known filter target with 422', function () {
    $this->getJson('/redline/api/report?filter[status:regex]=x', $this->devHeaders)->assertStatus(422);
});

it('rejects an unknown include token with 422', function () {
    $this->getJson('/redline/api/report?include[]=nope', $this->devHeaders)->assertStatus(422);
});

it('rejects a non-sortable attribute with 422', function () {
    $this->getJson('/redline/api/report?sort[body]=desc', $this->devHeaders)->assertStatus(422);
});

// ---------------------------------------------------------------------------
// Due dates - virtual `due` state filter, raw `due_at` date filter, nulls-last sort
// ---------------------------------------------------------------------------

it('carries due_at in the base row without being asked for via include[]', function () {
    storeReport(['location' => '/page', 'due_at' => Carbon::parse('2026-10-06T09:00:00Z')]);

    $body = $this->getJson('/redline/api/report?filter[location]=/page', $this->devHeaders)
        ->assertStatus(200)
        ->json('data');

    expect($body[0])->toHaveKeys(['id', 'status', 'priority', 'due_at', 'created_at'])
        ->and($body[0]['due_at'])->not->toBeNull();
});

it('filters open reports whose deadline has passed with filter[due]=overdue', function () {
    $this->travelTo(Carbon::parse('2026-10-05T12:00:00Z'));

    $overdue = storeReport(['location' => '/page', 'due_at' => Carbon::parse('2026-10-05T11:00:00Z')]);
    storeReport(['location' => '/page', 'due_at' => Carbon::parse('2026-10-05T12:00:00Z')]); // exactly now -> not overdue
    storeReport(['location' => '/page', 'due_at' => Carbon::parse('2026-10-06T09:00:00Z')]); // future
    storeReport(['location' => '/page']); // no deadline

    $body = $this->getJson('/redline/api/report?filter[due]=overdue', $this->devHeaders)
        ->assertStatus(200)
        ->json('data');

    expect($body)->toHaveCount(1)
        ->and($body[0]['id'])->toBe($overdue);
});

it('filters reports due within the next 24h with filter[due]=due_soon, exclusive at +24h', function () {
    $this->travelTo(Carbon::parse('2026-10-05T12:00:00Z'));

    $atNow = storeReport(['location' => '/page', 'due_at' => Carbon::parse('2026-10-05T12:00:00Z')]);
    $justInside = storeReport(['location' => '/page', 'due_at' => Carbon::parse('2026-10-06T11:59:59Z')]);
    storeReport(['location' => '/page', 'due_at' => Carbon::parse('2026-10-06T12:00:00Z')]); // exactly +24h -> excluded
    storeReport(['location' => '/page', 'due_at' => Carbon::parse('2026-10-05T11:00:00Z')]); // overdue, not due soon
    storeReport(['location' => '/page']); // no deadline

    $body = $this->getJson('/redline/api/report?filter[due]=due_soon', $this->devHeaders)
        ->assertStatus(200)
        ->json('data');

    expect(collect($body)->pluck('id')->sort()->values()->all())
        ->toBe(collect([$atNow, $justInside])->sort()->values()->all());
});

it('rejects an unknown due state with 422', function () {
    $this->getJson('/redline/api/report?filter[due]=soon', $this->devHeaders)
        ->assertStatus(422)
        ->assertJsonValidationErrors(['filter.due']);
});

it('never matches a closed report with filter[due], so overdue combined with resolved is empty', function () {
    $this->travelTo(Carbon::parse('2026-10-05T12:00:00Z'));

    $open = storeReport(['location' => '/page', 'due_at' => Carbon::parse('2026-10-05T10:00:00Z')]);
    storeReport(['location' => '/page', 'status' => 'resolved', 'due_at' => Carbon::parse('2026-10-05T10:00:00Z')]);

    $overdue = $this->getJson('/redline/api/report?filter[due]=overdue', $this->devHeaders)->json('data');

    expect($overdue)->toHaveCount(1)
        ->and($overdue[0]['id'])->toBe($open);

    $combined = $this->getJson('/redline/api/report?filter[due]=overdue&filter[status]=resolved', $this->devHeaders)->json('data');

    expect($combined)->toBe([]);
});

it('filters to undated reports across every status with filter[due]=none, excluding all dated ones', function () {
    $openUndated = storeReport(['location' => '/page']);
    $resolvedUndated = storeReport(['location' => '/page', 'status' => 'resolved']);
    $openDated = storeReport(['location' => '/page', 'due_at' => Carbon::parse('2026-10-06T09:00:00Z')]);
    $resolvedDated = storeReport(['location' => '/page', 'status' => 'resolved', 'due_at' => Carbon::parse('2026-10-06T09:00:00Z')]);

    $body = $this->getJson('/redline/api/report?filter[due]=none', $this->devHeaders)
        ->assertStatus(200)
        ->json('data');

    $ids = collect($body)->pluck('id');

    expect($ids->sort()->values()->all())
        ->toBe(collect([$openUndated, $resolvedUndated])->sort()->values()->all())
        ->and($ids)->not->toContain($openDated, $resolvedDated);
});

it('keeps filter[due]=none matching an undated resolved report while excluding a resolved one with a deadline', function () {
    $resolvedUndated = storeReport(['location' => '/page', 'status' => 'resolved']);
    storeReport(['location' => '/page', 'status' => 'resolved', 'due_at' => Carbon::parse('2026-10-06T09:00:00Z')]);
    storeReport(['location' => '/page']); // open + undated, excluded by the status filter

    $body = $this->getJson('/redline/api/report?filter[due]=none&filter[status]=resolved', $this->devHeaders)
        ->assertStatus(200)
        ->json('data');

    expect($body)->toHaveCount(1)
        ->and($body[0]['id'])->toBe($resolvedUndated);
});

it('rejects filter[due]=bogus with 422', function () {
    $this->getJson('/redline/api/report?filter[due]=bogus', $this->devHeaders)
        ->assertStatus(422)
        ->assertJsonValidationErrors(['filter.due']);
});

it('filters to reports with no due date via filter[due_at:null]', function () {
    $none = storeReport(['location' => '/page']);
    storeReport(['location' => '/page', 'due_at' => Carbon::parse('2026-10-06T09:00:00Z')]);

    $body = $this->getJson('/redline/api/report?filter[due_at:null]=1', $this->devHeaders)
        ->assertStatus(200)
        ->json('data');

    expect($body)->toHaveCount(1)
        ->and($body[0]['id'])->toBe($none);
});

it('range-filters due_at with filter[due_at:to]', function () {
    $early = storeReport(['location' => '/page', 'due_at' => Carbon::parse('2026-10-05T11:00:00Z')]);
    storeReport(['location' => '/page', 'due_at' => Carbon::parse('2026-10-06T11:00:00Z')]);

    $body = $this->getJson('/redline/api/report?filter[due_at:to]=2026-10-05%2012:00:00', $this->devHeaders)
        ->assertStatus(200)
        ->json('data');

    expect($body)->toHaveCount(1)
        ->and($body[0]['id'])->toBe($early);
});

it('sorts by due_at with NULL deadlines last in both directions', function () {
    $this->travelTo(Carbon::parse('2026-10-05T12:00:00Z'));

    $late = storeReport(['location' => '/page', 'due_at' => Carbon::parse('2026-10-08T09:00:00Z')]);
    $early = storeReport(['location' => '/page', 'due_at' => Carbon::parse('2026-10-06T09:00:00Z')]);
    $none = storeReport(['location' => '/page']);

    $asc = $this->getJson('/redline/api/report?sort[due_at]=asc', $this->devHeaders)->json('data');
    $desc = $this->getJson('/redline/api/report?sort[due_at]=desc', $this->devHeaders)->json('data');

    expect(collect($asc)->pluck('id')->all())->toBe([$early, $late, $none])
        ->and(collect($desc)->pluck('id')->all())->toBe([$late, $early, $none]);
});

it('breaks a due_at tie with a secondary created_at sort', function () {
    $this->travelTo(Carbon::parse('2026-10-05T12:00:00Z'));
    $sameDue = Carbon::parse('2026-10-07T09:00:00Z');

    $first = storeReport(['location' => '/page', 'due_at' => $sameDue]);
    $this->travel(1)->hour();
    $second = storeReport(['location' => '/page', 'due_at' => $sameDue]);

    $body = $this->getJson('/redline/api/report?sort[due_at]=asc&sort[created_at]=desc', $this->devHeaders)->json('data');

    expect(collect($body)->pluck('id')->all())->toBe([$second, $first]);
});

// ---------------------------------------------------------------------------
// Auth
// ---------------------------------------------------------------------------

it('returns 401 when no token is provided', function () {
    $this->getJson('/redline/api/report')
        ->assertStatus(401);
});
