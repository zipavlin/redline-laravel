<?php

use Illuminate\Support\Carbon;
use Zipavlin\Redline\Models\Comment;
use Zipavlin\Redline\Models\Report;
use Zipavlin\Redline\Models\Screenshot;
use Zipavlin\Redline\Models\User;
use Zipavlin\Redline\Models\Video;

beforeEach(fn () => ensureRedlineDirectories());

function makeReport(array $user, array $overrides = []): array
{
    return app(Report::class)->create(array_merge([
        'author_id' => $user['id'],
        'url' => 'https://staging.example.com/dashboard',
        'location' => '/dashboard',
        'body' => 'Something is broken.',
        'status' => 'new',
    ], $overrides))->toArray();
}

// ---------------------------------------------------------------------------
// Auth: me / refresh
// ---------------------------------------------------------------------------

it('auth/me returns the authenticated user and 401 without a token', function () {
    $user = makeUser('developer');
    $this->getJson('/redline/api/auth/me', ['Authorization' => 'Bearer '.makeAuthToken($user)])
        ->assertStatus(200)
        ->assertJsonPath('user.email', $user['email']);

    $this->getJson('/redline/api/auth/me')->assertStatus(401);
});

it('auth/refresh issues a fresh token for the current user', function () {
    $user = makeUser('reporter');
    $response = $this->getJson('/redline/api/auth/refresh', ['Authorization' => 'Bearer '.makeAuthToken($user)])
        ->assertStatus(200);

    $body = $response->json();

    expect($body)->toHaveKey('user')->not->toHaveKey('token')
        ->and($body['user']['id'])->toBe($user['id']);

    // The fresh JWT is delivered as the HttpOnly auth cookie, never in the body.
    $cookie = collect($response->baseResponse->headers->getCookies())
        ->first(fn ($c) => $c->getName() === 'redline_token');

    expect($cookie)->not->toBeNull()
        ->and($cookie->isHttpOnly())->toBeTrue()
        ->and($cookie->getValue())->toBeString()->not->toBeEmpty();
});

// ---------------------------------------------------------------------------
// The caller's own report tallies - was GET user/stats (deleted in Pass 2),
// now the grammar expresses it as /report/aggregate + filter[mine].
// ---------------------------------------------------------------------------

it('a caller can tally their own reports by status group via report/aggregate + filter[mine]', function () {
    $user = makeUser('reporter');
    makeReport($user, ['status' => 'new']);
    makeReport($user, ['status' => 'resolved']);
    makeReport($user, ['status' => 'done']);
    makeReport(makeUser('reporter'), ['status' => 'new']); // someone else's - must be excluded

    $body = $this->getJson(
        '/redline/api/report/aggregate?aggregate[status:count]=unresolved,resolved&filter[mine]=1',
        ['Authorization' => 'Bearer '.makeAuthToken($user)],
    )->assertStatus(200)->json();

    expect($body['status']['count'])->toBe(['unresolved' => 1, 'resolved' => 2]);
});

// ---------------------------------------------------------------------------
// report show
// ---------------------------------------------------------------------------

it('report show auto-transitions new -> noticed for a developer', function () {
    $reporter = makeUser('reporter');
    $report = makeReport($reporter, ['status' => 'new']);

    $this->getJson("/redline/api/report/{$report['id']}", authHeaders('developer'))
        ->assertStatus(200)
        ->assertJsonPath('status', 'noticed');

    expect(app(Report::class)->find($report['id'])->status)->toBe('noticed');
});

it('report show exposes browser (without user_agent) to developers only, when asked for via include[]', function () {
    $reporter = makeUser('reporter');
    $report = makeReport($reporter, ['browser' => ['browser_name' => 'Chrome', 'user_agent' => 'secret-ua']]);

    $dev = $this->getJson("/redline/api/report/{$report['id']}?include[]=browser", authHeaders('developer'))
        ->assertStatus(200)->json();
    expect($dev)->toHaveKey('browser')
        ->and($dev['browser'])->not->toHaveKey('user_agent');

    // A reporter viewing their own report gets the curated shape (no browser) even
    // though they asked for it - `browser` is role-gated, not just include-gated.
    $ownerToken = ['Authorization' => 'Bearer '.makeAuthToken($reporter)];
    $owner = $this->getJson("/redline/api/report/{$report['id']}?include[]=browser", $ownerToken)->assertStatus(200)->json();
    expect($owner)->not->toHaveKey('browser');
});

it('report show skips the auto acknowledge silently for a non developer caller', function () {
    $reporter = makeUser('reporter');
    $report = makeReport($reporter);

    expect($report['status'])->toBe('new');

    // A reporter's GET must be a plain read: no 500, no transition - the
    // acknowledge is the developer caller's side effect only.
    $this->getJson("/redline/api/report/{$report['id']}", [
        'Authorization' => 'Bearer '.makeAuthToken($reporter),
    ])->assertStatus(200);

    expect(app(Report::class)->find($report['id'])->status)->toBe('new');
});

it('pins the mark-noticed removal: show() acknowledges via updateStatus for developers, silently for others', function () {
    $reporter = makeUser('reporter');
    $devReport = makeReport($reporter, ['status' => 'new']);
    $ownReport = makeReport($reporter, ['status' => 'new']);

    // The mark-noticed action is gone; the show guard now calls the shared
    // ReportActions::updateStatus with Status::NOTICED - the transition and
    // the developer-only gate must be unchanged.
    $this->getJson("/redline/api/report/{$devReport['id']}", authHeaders('developer'))
        ->assertStatus(200)
        ->assertJsonPath('status', 'noticed');

    $this->getJson("/redline/api/report/{$ownReport['id']}", [
        'Authorization' => 'Bearer '.makeAuthToken($reporter),
    ])->assertStatus(200)
        ->assertJsonPath('status', 'new');

    expect(app(Report::class)->find($devReport['id'])->status)->toBe('noticed')
        ->and(app(Report::class)->find($ownReport['id'])->status)->toBe('new');
});

it('report show returns full screenshot, video and comments objects via bare include[]', function () {
    $reporter = makeUser('reporter');
    $report = makeReport($reporter);

    app(Screenshot::class)->create(['report_id' => $report['id'], 'path' => 'x.png']);
    app(Video::class)->create(['report_id' => $report['id'], 'path' => 'x.webm']);
    app(Comment::class)->create(['report_id' => $report['id'], 'author_id' => $reporter['id'], 'body' => 'hi']);

    $body = $this->getJson(
        "/redline/api/report/{$report['id']}?include[]=screenshot&include[]=video&include[]=comments",
        authHeaders('developer'),
    )->assertStatus(200)->json();

    expect($body['screenshot'])->toHaveKeys(['id', 'url'])
        ->and($body['video'])->toHaveKeys(['id', 'url'])
        ->and($body['comments'])->toHaveCount(1)
        ->and($body['comments'][0])->toHaveKeys(['id', 'body', 'created_at', 'author'])
        ->and($body['comments'][0]['author'])->toBe(['id' => $reporter['id'], 'name' => $reporter['name']]);
});

// ---------------------------------------------------------------------------
// report update - ownership gate
// ---------------------------------------------------------------------------

it('report update is allowed for the owner, forbidden for another reporter, a developer and an admin', function () {
    $owner = makeUser('reporter');
    $report = makeReport($owner);

    // Owner can edit their own body.
    $this->patchJson("/redline/api/report/{$report['id']}", ['body' => 'edited by owner'],
        ['Authorization' => 'Bearer '.makeAuthToken($owner)])->assertStatus(200);

    // Another reporter cannot.
    $this->patchJson("/redline/api/report/{$report['id']}", ['body' => 'hijack'], authHeaders('reporter'))
        ->assertStatus(403);

    // A developer cannot edit someone else's body.
    $this->patchJson("/redline/api/report/{$report['id']}", ['body' => 'edited by dev'], authHeaders('developer'))
        ->assertStatus(403);

    // An admin cannot either.
    $this->patchJson("/redline/api/report/{$report['id']}", ['body' => 'edited by admin'], authHeaders('admin'))
        ->assertStatus(403);
});

// ---------------------------------------------------------------------------
// report move
// ---------------------------------------------------------------------------

it('report move relocates the pin and validates coordinates', function () {
    $reporter = makeUser('reporter');
    $report = makeReport($reporter);
    $dev = authHeaders('developer');

    $this->postJson("/redline/api/report/{$report['id']}/move", ['x' => 12.5, 'y' => 34.0], $dev)
        ->assertStatus(200);

    // Missing coordinates -> 422.
    $this->postJson("/redline/api/report/{$report['id']}/move", ['x' => 12.5], $dev)
        ->assertStatus(422);
});

// ---------------------------------------------------------------------------
// report due date
// ---------------------------------------------------------------------------

it('report due_at endpoint sets and clears the deadline', function () {
    $reporter = makeUser('reporter');
    $report = makeReport($reporter);
    $dev = authHeaders('developer');

    $set = $this->patchJson("/redline/api/report/{$report['id']}/due_at", ['due_at' => '2026-10-10T12:00:00Z'], $dev)
        ->assertStatus(200)
        ->json();

    expect(Carbon::parse($set['due_at'])->equalTo(Carbon::parse('2026-10-10T12:00:00Z')))->toBeTrue();

    $this->patchJson("/redline/api/report/{$report['id']}/due_at", ['due_at' => null], $dev)
        ->assertStatus(200)
        ->assertJsonPath('due_at', null);
});

// ---------------------------------------------------------------------------
// dashboard filters + aggregate
// ---------------------------------------------------------------------------

it('dashboard list filters by comments:exists', function () {
    $reporter = makeUser('reporter');
    $withComment = makeReport($reporter);
    makeReport($reporter); // no comment

    $this->postJson("/redline/api/report/{$withComment['id']}/comment", ['body' => 'hi'], authHeaders('developer'))
        ->assertStatus(201);

    $body = $this->getJson('/redline/api/report?filter[comments:exists]=1', authHeaders('developer'))
        ->assertStatus(200)->json();

    expect($body['data'])->toHaveCount(1)
        ->and($body['data'][0]['id'])->toBe($withComment['id']);
});

it('report aggregate groups by location (broken down by status) and lists distinct authors, honoring filter[mine]', function () {
    $alice = makeUser('reporter');
    $bob = makeUser('reporter');
    makeReport($alice, ['location' => '/a']);
    makeReport($bob, ['location' => '/b']);

    $all = $this->getJson(
        '/redline/api/report/aggregate?aggregate[location:count]&group_by[]=status&aggregate[author:unique]',
        authHeaders('developer')
    )->assertStatus(200)->json();

    expect($all['location']['count'])->toHaveCount(2)
        ->and(collect($all['author']['unique'])->pluck('id'))->toContain($alice['id'], $bob['id']);

    // filter[mine] scopes the breakdown to the caller's own reports.
    $mine = $this->getJson(
        '/redline/api/report/aggregate?aggregate[location:count]&group_by[]=status&filter[mine]=1',
        ['Authorization' => 'Bearer '.makeAuthToken($alice)]
    )->assertStatus(200)->json();

    expect($mine['location']['count'])->toHaveCount(1)
        ->and($mine['location']['count'])->toHaveKey('/a');
});

it('screenshot binary answers with the shared path guard and still serves a real file', function () {
    // The traversal rule now has one home (Support\MediaFile::plainName), so
    // the binary endpoint refuses an unsafe stored name the same way
    // screenshot_get does, and keeps serving healthy rows unchanged.
    $developer = makeUser('developer');

    $unsafeReport = makeReport($developer);
    $unsafe = app(Screenshot::class)->create(['report_id' => $unsafeReport['id'], 'path' => '../escape.png']);

    $this->get("/redline/api/report/{$unsafeReport['id']}/screenshot/{$unsafe->id}/binary", authHeaders('developer'))
        ->assertStatus(404)
        ->assertJsonPath('message', 'Screenshot not found.');

    $safeReport = makeReport($developer);
    $safe = app(Screenshot::class)->create(['report_id' => $safeReport['id'], 'path' => newScreenshotFilePath()]);

    $this->get("/redline/api/report/{$safeReport['id']}/screenshot/{$safe->id}/binary", authHeaders('developer'))
        ->assertOk()
        // The mime guess is environment-dependent (fileinfo vs content sniffing),
        // so what is pinned here is the 200 file response, not the exact type.
        ->assertHeader('content-type');
});

it('a report show that 422s on include[] never acknowledges the report', function () {
    // The acknowledge write must come after the read is validated: before the
    // fix a 422ing include[] still left the report moved to noticed.
    $developer = makeUser('developer');
    $report = makeReport($developer);

    expect(app(Report::class)->find($report['id'])->status)->toBe('new');

    $this->getJson("/redline/api/report/{$report['id']}?include[]=nonsense", authHeaders('developer'))
        ->assertStatus(422);

    expect(app(Report::class)->find($report['id'])->status)->toBe('new');
});
