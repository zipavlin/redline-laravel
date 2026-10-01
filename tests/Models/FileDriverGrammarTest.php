<?php

use Zipavlin\Redline\Models\Base\Assignment;
use Zipavlin\Redline\Models\Base\Comment;
use Zipavlin\Redline\Models\Base\Report;
use Zipavlin\Redline\Models\Base\Screenshot;
use Zipavlin\Redline\Models\Base\User;
use Zipavlin\Redline\Providers\Storage\FileStorageProvider;

/**
 * Exercises the query grammar's `withCount`/`withExists`/`groupBy`/`whereHas`
 * paths against the Orbit-backed File storage driver - QuerySchema's own
 * docblock claims both storage drivers share the grammar, and this is the
 * only place that claim is actually run rather than reasoned about. Orbit
 * hydrates flat files into a SQLite connection and runs real Eloquent
 * against it, so these should behave identically to the Database driver.
 *
 * ryangjchandler/orbit isn't in composer.lock in this environment (it's a
 * `suggest`, not a hard dependency), so the whole file is skipped unless
 * it's installed - do not remove this guard, it is not a decoration.
 */
beforeEach(function () {
    if (! trait_exists('Orbit\Concerns\Orbital')) {
        $this->markTestSkipped('ryangjchandler/orbit is not installed - the File storage driver is untestable here.');
    }

    cleanJsonStorage();
    ensureRedlineDirectories();
    (new FileStorageProvider)->boot();

    $this->devHeaders = authHeaders('developer');
});

it('whereHas: filters authors by relation-attribute delegation (filter[author.name:like])', function () {
    $match = app(User::class)->create(['name' => 'Zig Zag', 'email' => 'zig@example.com', 'role' => 'developer']);
    $other = app(User::class)->create(['name' => 'Someone Else', 'email' => 'else@example.com', 'role' => 'reporter']);

    app(Report::class)->create(['author_id' => $match->id, 'url' => 'https://x/y', 'location' => '/y', 'body' => 'a', 'status' => 'new']);
    app(Report::class)->create(['author_id' => $other->id, 'url' => 'https://x/y', 'location' => '/y', 'body' => 'b', 'status' => 'new']);

    $body = $this->getJson('/redline/api/report?filter[author.name:like]=Zig', $this->devHeaders)
        ->assertStatus(200)
        ->json('data');

    expect($body)->toHaveCount(1);
});

it('withCount + withExists: include[comments:count] and include[screenshot:exists] both work', function () {
    $reporter = app(User::class)->create(['name' => 'Reporter', 'email' => 'r@example.com', 'role' => 'reporter']);
    $report = app(Report::class)->create([
        'author_id' => $reporter->id, 'url' => 'https://x/y', 'location' => '/y', 'body' => 'a', 'status' => 'new',
    ]);
    app(Comment::class)->create(['report_id' => $report->id, 'author_id' => $reporter->id, 'body' => 'hi']);
    app(Screenshot::class)->create(['report_id' => $report->id, 'path' => 'x.png']);

    $body = $this->getJson(
        '/redline/api/report?include[]=comments:count&include[]=screenshot:exists&include[]=video:exists',
        $this->devHeaders,
    )->assertStatus(200)->json('data');

    expect($body[0]['comments_count'])->toBe(1)
        ->and($body[0]['screenshot_exists'])->toBeTrue()
        ->and($body[0]['video_exists'])->toBeFalse();
});

it('groupBy: aggregate[location:count]&group_by[]=status nests per-location status breakdowns', function () {
    $user = app(User::class)->create(['name' => 'Reporter', 'email' => 'r2@example.com', 'role' => 'reporter']);
    app(Report::class)->create(['author_id' => $user->id, 'url' => 'https://x/a', 'location' => '/a', 'body' => 'a', 'status' => 'new']);
    app(Report::class)->create(['author_id' => $user->id, 'url' => 'https://x/a', 'location' => '/a', 'body' => 'b', 'status' => 'resolved']);
    app(Report::class)->create(['author_id' => $user->id, 'url' => 'https://x/b', 'location' => '/b', 'body' => 'c', 'status' => 'new']);

    $body = $this->getJson(
        '/redline/api/report/aggregate?aggregate[location:count]&group_by[]=status',
        $this->devHeaders,
    )->assertStatus(200)->json();

    expect($body['location']['count']['/a'])->toBe(['new' => 1, 'resolved' => 1])
        ->and($body['location']['count']['/b'])->toBe(['new' => 1]);
});

it('include[assignees]: loads sorted assignees and target-named count/exists from the junction', function () {
    $reporter = app(User::class)->create(['name' => 'Reporter', 'email' => 'assignee-reporter@example.com', 'role' => 'reporter']);
    $zoe = app(User::class)->create(['name' => 'Zoe Zulu', 'email' => 'zoe-file@example.com', 'role' => 'developer']);
    $amy = app(User::class)->create(['name' => 'Amy Alpha', 'email' => 'amy-file@example.com', 'role' => 'developer']);

    $report = app(Report::class)->create([
        'author_id' => $reporter->id, 'url' => 'https://x/y', 'location' => '/y', 'body' => 'a', 'status' => 'new',
    ]);

    app(Assignment::class)->create(['report_id' => $report->id, 'user_id' => $zoe->id]);
    app(Assignment::class)->create(['report_id' => $report->id, 'user_id' => $amy->id]);

    $body = $this->getJson(
        "/redline/api/report/{$report->id}?include[]=assignees&include[]=assignees:count&include[]=assignees:exists",
        $this->devHeaders,
    )->assertStatus(200)->json();

    expect(array_column($body['assignees'], 'name'))->toBe(['Amy Alpha', 'Zoe Zulu'])
        ->and($body['assignees_count'])->toBe(2)
        ->and($body['assignees_exists'])->toBeTrue();
});

it('filter[assignee] and filter[assignee:in] resolve through the junction', function () {
    $reporter = app(User::class)->create(['name' => 'Reporter', 'email' => 'r3@example.com', 'role' => 'reporter']);
    $assignee = app(User::class)->create(['name' => 'Assignee', 'email' => 'a3@example.com', 'role' => 'developer']);
    $other = app(User::class)->create(['name' => 'Other', 'email' => 'o3@example.com', 'role' => 'developer']);

    $first = app(Report::class)->create(['author_id' => $reporter->id, 'url' => 'https://x/a', 'location' => '/a', 'body' => 'a', 'status' => 'new']);
    $second = app(Report::class)->create(['author_id' => $reporter->id, 'url' => 'https://x/b', 'location' => '/b', 'body' => 'b', 'status' => 'new']);

    app(Assignment::class)->create(['report_id' => $first->id, 'user_id' => $assignee->id]);
    app(Assignment::class)->create(['report_id' => $second->id, 'user_id' => $other->id]);

    $single = $this->getJson('/redline/api/report?filter[assignee]='.$assignee->id, $this->devHeaders)
        ->assertStatus(200)->json('data');
    $both = $this->getJson('/redline/api/report?filter[assignee:in]='.$assignee->id.','.$other->id, $this->devHeaders)
        ->assertStatus(200)->json('data');

    expect($single)->toHaveCount(1)
        ->and($single[0]['id'])->toBe($first->id)
        ->and($both)->toHaveCount(2);
});

it('filter[assigned] separates the caller reports from the rest', function () {
    $me = app(User::class)->create(['name' => 'Me', 'email' => 'me-file@example.com', 'role' => 'developer']);
    $headers = ['Authorization' => 'Bearer '.makeAuthToken($me->toArray())];

    $mine = app(Report::class)->create(['author_id' => $me->id, 'url' => 'https://x/a', 'location' => '/a', 'body' => 'a', 'status' => 'new']);
    $other = app(Report::class)->create(['author_id' => $me->id, 'url' => 'https://x/b', 'location' => '/b', 'body' => 'b', 'status' => 'new']);

    app(Assignment::class)->create(['report_id' => $mine->id, 'user_id' => $me->id]);

    $assigned = $this->getJson('/redline/api/report?filter[assigned]=1', $headers)->assertStatus(200)->json('data');
    $rest = $this->getJson('/redline/api/report?filter[assigned]=0', $headers)->assertStatus(200)->json('data');

    expect($assigned)->toHaveCount(1)
        ->and($assigned[0]['id'])->toBe($mine->id)
        ->and(collect($rest)->pluck('id')->all())->toBe([$other->id]);
});

it('deduplicates repeated assignee writes at the application level (no unique index on the File driver)', function () {
    $reporter = app(User::class)->create(['name' => 'Reporter', 'email' => 'assignee-dedup-reporter@example.com', 'role' => 'reporter']);
    $assignee = app(User::class)->create(['name' => 'Dedup Dev', 'email' => 'assignee-dedup@example.com', 'role' => 'developer']);
    $report = app(Report::class)->create([
        'author_id' => $reporter->id, 'url' => 'https://x/y', 'location' => '/y', 'body' => 'a', 'status' => 'new',
    ]);

    $url = "/redline/api/report/{$report->id}/assignee";

    $this->postJson($url, ['user_ids' => [$assignee->id]], $this->devHeaders)->assertStatus(200);
    $body = $this->postJson($url, ['user_ids' => [$assignee->id]], $this->devHeaders)->assertStatus(200)->json();

    expect($body['assignees'])->toHaveCount(1)
        ->and(app(Assignment::class)->where('report_id', $report->id)->count())->toBe(1);
});

it('writes and removes assignments through the API on the File driver', function () {
    $reporter = app(User::class)->create(['name' => 'Reporter', 'email' => 'assignee-write-reporter@example.com', 'role' => 'reporter']);
    $first = app(User::class)->create(['name' => 'First File', 'email' => 'first-file@example.com', 'role' => 'developer']);
    $second = app(User::class)->create(['name' => 'Second File', 'email' => 'second-file@example.com', 'role' => 'developer']);
    $report = app(Report::class)->create([
        'author_id' => $reporter->id, 'url' => 'https://x/y', 'location' => '/y', 'body' => 'a', 'status' => 'new',
    ]);

    $url = "/redline/api/report/{$report->id}/assignee";

    $posted = $this->postJson($url, ['user_ids' => [$first->id, $second->id]], $this->devHeaders)
        ->assertStatus(200)->json();

    expect($posted['assignees'])->toHaveCount(2)
        ->and(app(Assignment::class)->where('report_id', $report->id)->count())->toBe(2);

    $deleted = $this->deleteJson($url, ['user_ids' => [$first->id]], $this->devHeaders)
        ->assertStatus(200)->json();

    expect($deleted['assignees'])->toHaveCount(1)
        ->and($deleted['assignees'][0]['id'])->toBe($second->id)
        ->and(app(Assignment::class)->where('report_id', $report->id)->count())->toBe(1)
        ->and(app(Assignment::class)->where('user_id', $first->id)->exists())->toBeFalse();
});
