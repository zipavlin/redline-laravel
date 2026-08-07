<?php

use Zipavlin\Redline\Models\Base\Comment;
use Zipavlin\Redline\Models\Base\Report;
use Zipavlin\Redline\Models\Base\Screenshot;
use Zipavlin\Redline\Models\Base\User;
use Zipavlin\Redline\Models\Base\Video;

beforeEach(function () {
    ensureRedlineDirectories();
    $this->devHeaders = authHeaders('developer');
});

// ---------------------------------------------------------------------------
// Helper - create a report attributed to a specific user.
// ---------------------------------------------------------------------------

function dashboardReport(string $userId, array $overrides = []): Report
{
    return app(Report::class)->create(array_merge([
        'author_id' => $userId,
        'url' => 'https://staging.example.com/dashboard',
        'location' => '/dashboard',
        'body' => 'Bug report body.',
        'status' => 'new',
    ], $overrides));
}

// The dashboard's row shape, expressed as include[] tokens.
const DASHBOARD_INCLUDE = 'include[]=title&include[]=url&include[]=location'
    .'&include[]=comments:count&include[]=screenshot:exists&include[]=video:exists'
    .'&include[author]=id,name';

// ---------------------------------------------------------------------------
// Dashboard index
// ---------------------------------------------------------------------------

it('returns the paginated {data, meta} envelope with the dashboard row shape', function () {
    $user = makeUser('reporter');
    foreach (range(1, 20) as $i) {
        dashboardReport($user['id'], ['location' => "/page-{$i}"]);
    }

    $body = $this->getJson('/redline/api/report?paginate[per_page]=15&'.DASHBOARD_INCLUDE, $this->devHeaders)
        ->assertStatus(200)
        ->json();

    expect($body)->toHaveKeys(['data', 'meta'])
        ->and($body['data'])->toHaveCount(15)
        ->and($body['meta']['total'])->toBe(20)
        ->and($body['meta']['per_page'])->toBe(15)
        ->and($body['meta']['last_page'])->toBe(2)
        ->and($body['data'][0])->toHaveKeys([
            'id', 'status', 'title', 'url', 'location',
            'author', 'created_at', 'screenshot_exists', 'video_exists', 'comments_count',
        ])
        ->and($body['data'][0])->not->toHaveKey('body');
});

it('derives a title from the first line of the body truncated to ~60 chars', function () {
    $user = makeUser('reporter');
    $long = str_repeat('a', 100);
    dashboardReport($user['id'], ['body' => "First line here\nSecond line"]);
    dashboardReport($user['id'], ['body' => $long]);

    $body = $this->getJson('/redline/api/report?'.DASHBOARD_INCLUDE, $this->devHeaders)->json();
    $titles = collect($body['data'])->pluck('title');

    expect($titles)->toContain('First line here')
        ->and($titles->first(fn ($t) => str_ends_with($t, '...')))->not->toBeNull();
});

it('filters by unresolved status group', function () {
    $user = makeUser('reporter');
    dashboardReport($user['id'], ['status' => 'new']);
    dashboardReport($user['id'], ['status' => 'resolved']);
    dashboardReport($user['id'], ['status' => 'done']);

    $body = $this->getJson('/redline/api/report?filter[status]=unresolved', $this->devHeaders)->json();

    expect($body['data'])->toHaveCount(1)
        ->and($body['data'][0]['status'])->toBe('new');
});

it('filters by resolved status group', function () {
    $user = makeUser('reporter');
    dashboardReport($user['id'], ['status' => 'new']);
    dashboardReport($user['id'], ['status' => 'resolved']);
    dashboardReport($user['id'], ['status' => 'done']);

    $body = $this->getJson('/redline/api/report?filter[status]=resolved', $this->devHeaders)->json();

    expect($body['data'])->toHaveCount(2);
});

it('filters to the authenticated user reports with filter[mine]=1', function () {
    $mine = makeUser('developer');
    $other = makeUser('reporter');
    dashboardReport($mine['id']);
    dashboardReport($other['id']);

    $headers = ['Authorization' => 'Bearer '.makeAuthToken($mine)];

    $body = $this->getJson('/redline/api/report?filter[mine]=1', $headers)->json();

    expect($body['data'])->toHaveCount(1);
});

it('searches over body and location', function () {
    $user = makeUser('reporter');
    dashboardReport($user['id'], ['body' => 'The login button is broken', 'location' => '/a']);
    dashboardReport($user['id'], ['body' => 'Something else', 'location' => '/checkout']);

    $byBody = $this->getJson('/redline/api/report?filter[search:like]=login', $this->devHeaders)->json('data');
    $byKey = $this->getJson('/redline/api/report?filter[search:like]=checkout', $this->devHeaders)->json('data');

    expect($byBody)->toHaveCount(1)
        ->and($byKey)->toHaveCount(1);
});

it('reports screenshot_exists, video_exists and comments_count flags', function () {
    $user = makeUser('reporter');
    $report = dashboardReport($user['id']);
    app(Comment::class)->create(['report_id' => $report->id, 'author_id' => $user['id'], 'body' => 'hi']);

    $body = $this->getJson('/redline/api/report?'.DASHBOARD_INCLUDE, $this->devHeaders)->json('data');

    expect($body[0]['screenshot_exists'])->toBeFalse()
        ->and($body[0]['video_exists'])->toBeFalse()
        ->and($body[0]['comments_count'])->toBe(1)
        ->and($body[0]['author'])->toHaveKeys(['id', 'name']);
});

it('filters by comments:exists', function () {
    $user = makeUser('reporter');
    $withComment = dashboardReport($user['id']);
    dashboardReport($user['id']); // no comment
    app(Comment::class)->create(['report_id' => $withComment->id, 'author_id' => $user['id'], 'body' => 'hi']);

    $with = $this->getJson('/redline/api/report?filter[comments:exists]=1', $this->devHeaders)->json('data');
    $without = $this->getJson('/redline/api/report?filter[comments:exists]=0', $this->devHeaders)->json('data');

    expect($with)->toHaveCount(1)
        ->and($with[0]['id'])->toBe($withComment->id)
        ->and($without)->toHaveCount(1)
        ->and($without[0]['id'])->not->toBe($withComment->id);
});

it('rejects an unrecognized :exists value with 422', function () {
    $this->getJson('/redline/api/report?filter[comments:exists]=maybe', $this->devHeaders)
        ->assertStatus(422);
});

it('combines screenshot:exists and video:exists as AND, not OR - has_media is gone', function () {
    $user = makeUser('reporter');
    $withScreenshotOnly = dashboardReport($user['id']);
    $withBoth = dashboardReport($user['id']);
    dashboardReport($user['id']); // neither

    app(Screenshot::class)->create(['report_id' => $withScreenshotOnly->id, 'path' => 'a.png']);
    app(Screenshot::class)->create(['report_id' => $withBoth->id, 'path' => 'b.png']);
    app(Video::class)->create(['report_id' => $withBoth->id, 'path' => 'b.webm']);

    // filter[screenshot:exists]=1 alone catches both reports with a screenshot -
    // the "has any media" OR that has_media used to express.
    $anyScreenshot = $this->getJson('/redline/api/report?filter[screenshot:exists]=1', $this->devHeaders)->json('data');
    expect($anyScreenshot)->toHaveCount(2);

    // Combined, the two filter[]s are AND-combined (the grammar's KNOWN BOUND),
    // so only the report with BOTH survives - "has either" is no longer
    // expressible in one call now that has_media is gone.
    $both = $this->getJson('/redline/api/report?filter[screenshot:exists]=1&filter[video:exists]=1', $this->devHeaders)->json('data');
    expect($both)->toHaveCount(1)
        ->and($both[0]['id'])->toBe($withBoth->id);
});

// ---------------------------------------------------------------------------
// Aggregate (replaces the old /report/overview)
// ---------------------------------------------------------------------------

it('keeps counts stable across status tabs when filter[status] is omitted from the aggregate call', function () {
    $user = makeUser('reporter');
    dashboardReport($user['id'], ['status' => 'new']);
    dashboardReport($user['id'], ['status' => 'resolved']);
    dashboardReport($user['id'], ['status' => 'done']);

    $query = 'aggregate[status:count]=unresolved,resolved';
    $fromUnresolvedTab = $this->getJson("/redline/api/report/aggregate?{$query}", $this->devHeaders)->json();
    $fromResolvedTab = $this->getJson("/redline/api/report/aggregate?{$query}", $this->devHeaders)->json();

    expect($fromUnresolvedTab['status']['count'])->toBe(['unresolved' => 1, 'resolved' => 2])
        ->and($fromResolvedTab)->toBe($fromUnresolvedTab);
});

it('counts rows grouped by location with no explicit value list', function () {
    $user = makeUser('reporter');
    dashboardReport($user['id'], ['location' => '/a']);
    dashboardReport($user['id'], ['location' => '/a']);
    dashboardReport($user['id'], ['location' => '/b']);

    $body = $this->getJson('/redline/api/report/aggregate?aggregate[location:count]', $this->devHeaders)->json();

    expect($body['location']['count'])->toBe(['/a' => 2, '/b' => 1]);
});

it('returns a distinct author list via the unique aggregate operator', function () {
    $a = makeUser('reporter');
    $b = makeUser('developer');
    dashboardReport($a['id']);
    dashboardReport($a['id']);
    dashboardReport($b['id']);

    $body = $this->getJson('/redline/api/report/aggregate?aggregate[author:unique]', $this->devHeaders)->json();

    expect($body['author']['unique'])->toHaveCount(2)
        ->and(collect($body['author']['unique'])->pluck('id')->sort()->values()->all())
        ->toBe(collect([$a['id'], $b['id']])->sort()->values()->all());
});

it('counts reports grouped by author id via the count aggregate operator', function () {
    $a = makeUser('reporter');
    $b = makeUser('developer');
    dashboardReport($a['id']);
    dashboardReport($a['id']);
    dashboardReport($b['id']);

    $body = $this->getJson('/redline/api/report/aggregate?aggregate[author:count]', $this->devHeaders)->json();

    expect($body['author']['count'][$a['id']])->toBe(2)
        ->and($body['author']['count'][$b['id']])->toBe(1);
});

it('scopes the author:count aggregate by filter[]', function () {
    $a = makeUser('reporter');
    $b = makeUser('developer');
    dashboardReport($a['id'], ['status' => 'new']);
    dashboardReport($a['id'], ['status' => 'resolved']);
    dashboardReport($b['id'], ['status' => 'new']);

    $body = $this->getJson('/redline/api/report/aggregate?aggregate[author:count]&filter[status]=new', $this->devHeaders)->json();

    expect($body['author']['count'][$a['id']])->toBe(1)
        ->and($body['author']['count'][$b['id']])->toBe(1);
});

it('rejects an unknown aggregate target with 422', function () {
    $this->getJson('/redline/api/report/aggregate?aggregate[nope:count]', $this->devHeaders)->assertStatus(422);
});
