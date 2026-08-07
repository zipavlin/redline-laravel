<?php

use Zipavlin\Redline\Models\Base\Report;
use Zipavlin\Redline\Models\Base\User;

beforeEach(function () {
    ensureRedlineDirectories();
    $this->devHeaders = authHeaders('developer');
});

// ---------------------------------------------------------------------------
// Helper — create a report directly via models.
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
// Happy path — the widget's old ?url_key= mode is now filter[location]=
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

it('shapes rows by include[] — base fields plus only what was asked for', function () {
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

it('gates browser on include[browser] by role — developers get it, reporters get silent omission', function () {
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

it('rejects filter[author.email:like] with 422 — a relation-attribute filter is a boolean oracle, so only attributes User::querySchema() declares are reachable', function () {
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
// Auth
// ---------------------------------------------------------------------------

it('returns 401 when no token is provided', function () {
    $this->getJson('/redline/api/report')
        ->assertStatus(401);
});
