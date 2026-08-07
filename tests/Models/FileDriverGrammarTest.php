<?php

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
