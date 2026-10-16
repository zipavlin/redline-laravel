<?php

namespace Zipavlin\Redline\Tests\Mcp;

use Illuminate\Support\Str;
use Laravel\Mcp\Server\Content\Image;
use Zipavlin\Redline\Enums\Role;
use Zipavlin\Redline\Mcp\Tools\CommentListTool;
use Zipavlin\Redline\Mcp\Tools\ReportGetTool;
use Zipavlin\Redline\Mcp\Tools\ReportListTool;
use Zipavlin\Redline\Mcp\Tools\ScreenshotGetTool;
use Zipavlin\Redline\Mcp\Tools\UserListTool;
use Zipavlin\Redline\Models\Comment;
use Zipavlin\Redline\Models\Report;
use Zipavlin\Redline\Models\Screenshot;
use Zipavlin\Redline\Models\User;
use Zipavlin\Redline\Models\Video;

/**
 * Milestone 1 read-tool matrix, driven through each tool's public handle()
 * (the exact method the MCP invoker resolves when wired). The wire is checked
 * separately in McpToolsWireTest.
 */
it('list rows are compact and never carry the body', function () {
    $user = makeMcpCallerUser('developer');
    createMcpReport($user, ['body' => str_repeat('a very long body ', 200)]);

    $listing = mcpToolPayload(mcpToolRun(ReportListTool::class, [], $user));

    expect($listing['data'])->toHaveCount(1);
    expect(array_keys($listing['data'][0]))->toBe([
        'id',
        'status',
        'created_at',
        'priority',
        'due_at',
        'title',
        'url',
        'location',
        'author',
        'comments_count',
        'screenshot_exists',
        'video_exists',
    ]);
    expect($listing['data'][0]['author'])->toBe(['id' => $user->id, 'name' => $user->name]);
    expect($listing['meta']['per_page'])->toBe(50);
    expect($listing['meta']['total'])->toBe(1);
});

it('list rows carry the report address values', function () {
    $user = makeMcpCallerUser('developer');
    $report = createMcpReport($user);

    $row = mcpToolPayload(mcpToolRun(ReportListTool::class, [], $user))['data'][0];

    expect($row['url'])->toBe($report->url);
    expect($row['location'])->toBe($report->location);
});

it('list rows carry the triage counts and media existence', function () {
    $user = makeMcpCallerUser('developer');
    $report = createMcpReport($user);
    app(Comment::class)->create(['report_id' => $report->id, 'author_id' => $user->id, 'body' => 'one']);
    app(Comment::class)->create(['report_id' => $report->id, 'author_id' => $user->id, 'body' => 'two']);
    app(Screenshot::class)->create(['id' => (string) Str::uuid(), 'report_id' => $report->id, 'path' => 'listed.png']);

    $row = mcpToolPayload(mcpToolRun(ReportListTool::class, [], $user))['data'][0];

    expect($row['comments_count'])->toBe(2);
    expect($row['screenshot_exists'])->toBeTrue();
    expect($row['video_exists'])->toBeFalse();
});

it('mine scopes to the synthetic request user only', function () {
    $reporter = makeMcpCallerUser('reporter');
    $developer = makeMcpCallerUser('developer');

    $own = createMcpReport($reporter);
    $dev = createMcpReport($developer);
    createMcpReport();

    $reporterRows = mcpToolPayload(mcpToolRun(ReportListTool::class, ['mine' => true], $reporter))['data'];
    $developerRows = mcpToolPayload(mcpToolRun(ReportListTool::class, ['mine' => true], $developer))['data'];

    expect(array_column($reporterRows, 'id'))->toBe([$own->id]);
    expect(array_column($developerRows, 'id'))->toBe([$dev->id]);
});

it('the mine filter without a user fails loudly not as zero rows', function () {
    createMcpReport();

    $response = mcpToolRun(ReportListTool::class, ['mine' => true], null);

    expect($response->isError())->toBeTrue();
    expect(mcpToolText($response))->toBe('No authenticated Redline user on this request.');
});

it('invalid filter values surface the grammars actionable errors', function () {
    $user = makeMcpCallerUser('developer');

    expect(mcpToolText(mcpToolRun(ReportListTool::class, ['status' => 'bogus'], $user)))
        ->toBe('Unknown status or status group [bogus].');
    expect(mcpToolText(mcpToolRun(ReportListTool::class, ['priority' => 'urgent'], $user)))
        ->toBe('Unknown priority value [urgent].');
    expect(mcpToolText(mcpToolRun(ReportListTool::class, ['sort' => 'created_at'], $user)))
        ->toContain('Invalid sort');
});

it('a matching rows result is distinguishable from a rejected filter', function () {
    $user = makeMcpCallerUser('developer');
    createMcpReport($user, ['status' => 'new']);

    $kept = mcpToolPayload(mcpToolRun(ReportListTool::class, ['status' => 'new'], $user));
    $rejected = mcpToolRun(ReportListTool::class, ['status' => 'nju'], $user);

    expect($kept['data'])->toHaveCount(1);
    expect($rejected->isError())->toBeTrue();
    expect(mcpToolText($rejected))->toContain('Unknown status or status group [nju].');
});

it('url and location inputs contains match', function () {
    $user = makeMcpCallerUser('developer');
    createMcpReport($user, ['url' => 'https://staging.example.com/settings?page=2', 'location' => '/settings']);
    createMcpReport($user, ['url' => 'https://staging.example.com/other', 'location' => '/other']);

    $byUrl = mcpToolPayload(mcpToolRun(ReportListTool::class, ['url' => 'settings'], $user))['data'];
    $byLocation = mcpToolPayload(mcpToolRun(ReportListTool::class, ['location' => '/other'], $user))['data'];

    expect($byUrl)->toHaveCount(1);
    expect($byLocation)->toHaveCount(1);
});

it('priority sort is enum ordered not lexical', function () {
    $user = makeMcpCallerUser('developer');
    foreach (['low', 'critical', 'high'] as $priority) {
        createMcpReport($user, ['priority' => $priority]);
    }

    $priorities = array_column(
        mcpToolPayload(mcpToolRun(ReportListTool::class, ['sort' => 'priority:desc'], $user))['data'],
        'priority',
    );

    expect($priorities)->toBe(['critical', 'high', 'low']);
});

it('per page is clamped at fifty', function () {
    $user = makeMcpCallerUser('developer');
    foreach (range(1, 3) as $i) {
        createMcpReport($user, ['location' => "/page-{$i}"]);
    }

    $clamped = mcpToolPayload(mcpToolRun(ReportListTool::class, ['per_page' => 999], $user));

    expect($clamped['meta']['per_page'])->toBe(50);
    expect($clamped['meta']['total'])->toBe(3);
    expect(mcpToolText(mcpToolRun(ReportListTool::class, ['per_page' => 'bogus'], $user)))
        ->toContain('per_page must be between 1 and 100.');
});

it('get report is a pure read and mark noticed is explicit', function () {
    $developer = makeMcpCallerUser('developer');
    $report = createMcpReport($developer);

    mcpToolRun(ReportGetTool::class, ['report' => $report->id], $developer);

    // A plain read must not acknowledge the report.
    expect($report->fresh()->status)->toBe('new');
    expect(Report::find($report->id)->status)->toBe('new');

    mcpToolRun(ReportGetTool::class, ['report' => $report->id, 'mark_noticed' => true], $developer);

    expect($report->fresh()->status)->toBe('noticed');

    // Not-new anymore: nothing left to acknowledge, a repeat call is a no-op.
    mcpToolRun(ReportGetTool::class, ['report' => $report->id, 'mark_noticed' => true], $developer);

    expect($report->fresh()->status)->toBe('noticed');
});

it('only developers may mark noticed explicitly', function () {
    $reporter = makeMcpCallerUser('reporter');
    $report = createMcpReport($reporter);

    $response = mcpToolRun(ReportGetTool::class, ['report' => $report->id, 'mark_noticed' => true], $reporter);

    expect($response->isError())->toBeTrue();
    expect(mcpToolText($response))->toBe('Only developers may mark a report noticed.');
    expect($report->fresh()->status)->toBe('new');
});

it('browser metadata and the author email are developer only', function () {
    $developer = makeMcpCallerUser('developer');
    $reporter = makeMcpCallerUser('reporter');
    $report = createMcpReport($developer, [
        'browser' => ['user_agent' => 'UA-STRING-THAT-MUST-NOT-LEAK', 'browser_name' => 'Chrome'],
    ]);

    $seen = mcpToolPayload(mcpToolRun(ReportGetTool::class, ['report' => $report->id], $reporter));
    $broad = mcpToolPayload(mcpToolRun(ReportGetTool::class, ['report' => $report->id], $developer));

    expect($seen)->not->toHaveKey('browser');
    expect($seen['author'])->not->toHaveKey('email');
    expect($broad['browser']['browser_name'])->toBe('Chrome');
    expect($broad['author']['email'])->toBe($developer->email);
    expect($broad['browser'])->not->toHaveKey('user_agent');
    expect(json_encode($broad))->not->toContain('UA-STRING-THAT-MUST-NOT-LEAK');
});

it('the full read carries media metadata counts and author excluded members', function () {
    $developer = makeMcpCallerUser('developer');
    $reporter = makeMcpCallerUser('reporter');
    $report = createMcpReport($developer);
    $screenshot = app(Screenshot::class)->create(['id' => (string) Str::uuid(), 'report_id' => $report->id, 'path' => 'shot.png']);
    $video = app(Video::class)->create(['id' => (string) Str::uuid(), 'report_id' => $report->id, 'path' => 'clip.webm']);
    app(Comment::class)->create(['report_id' => $report->id, 'author_id' => $reporter->id, 'body' => 'one']);

    $report->members()->syncWithoutDetaching([$reporter->id]);

    $row = mcpToolPayload(mcpToolRun(ReportGetTool::class, ['report' => $report->id], $developer));

    expect($row['author']['id'])->toBe($developer->id);
    expect(array_column($row['members'], 'id'))->toBe([$reporter->id]);
    expect($row['comments_count'])->toBe(1);
    // Media metadata carries the model's own id (the row UUID) plus its binary
    // URL - the same {id, url} projection HTTP's include[] returns; the stored
    // file name is a storage detail, never the id.
    expect($row['screenshot']['id'])->toBe($screenshot->id);
    expect($row['screenshot']['url'])->toContain('/screenshot/'.$screenshot->id.'/binary');
    expect($row['video']['id'])->toBe($video->id);
    expect($row['video']['url'])->toContain('/video/'.$video->id.'/binary');
    expect($row['due_at'])->toBeNull();
    expect($row['status'])->toBe('new');
    expect($row['priority'])->toBe('normal');
});

it('reports resolve from uuid and both address forms', function () {
    $user = makeMcpCallerUser('developer');
    $report = createMcpReport($user);

    $byId = mcpToolPayload(mcpToolRun(ReportGetTool::class, ['report' => $report->id], $user));
    $byLink = mcpToolPayload(mcpToolRun(ReportGetTool::class,
        ['url' => 'https://app.example.com/redline?redline-report='.$report->id], $user));
    $byTrailing = mcpToolPayload(mcpToolRun(ReportGetTool::class,
        ['url' => 'https://app.example.com/pages/'.$report->id], $user));

    expect($byId['id'])->toBe($report->id);
    expect($byLink['id'])->toBe($report->id);
    expect($byTrailing['id'])->toBe($report->id);

    $comments = mcpToolPayload(mcpToolRun(CommentListTool::class,
        ['url' => 'https://app.example.com/pages/'.$report->id], $user));

    expect($comments['data'])->toBe([]);
});

it('bad addresses are actionable never silent', function () {
    $user = makeMcpCallerUser('developer');
    createMcpReport($user);

    $unparseable = mcpToolRun(ReportGetTool::class, ['url' => 'https://app.example.com/nowhere'], $user);
    $unknown = mcpToolRun(ReportGetTool::class, ['report' => (string) Str::uuid()], $user);
    $both = mcpToolRun(ReportGetTool::class, ['report' => '11111111-1111-4111-8111-111111111111', 'url' => 'x'], $user);
    $neither = mcpToolRun(CommentListTool::class, [], $user);

    expect(mcpToolText($unparseable))
        ->toContain('The [url] value [https://app.example.com/nowhere] does not address a report');
    expect(mcpToolText($unknown))->toContain('No report found');
    expect(mcpToolText($both))->toContain('not both');
    expect(mcpToolText($neither))->toContain('Provide [report]');
});

it('screenshot absence file loss and traversal are actionable', function () {
    $user = makeMcpCallerUser('developer');

    $absent = createMcpReport($user);
    expect(mcpToolText(mcpToolRun(ScreenshotGetTool::class, ['report' => $absent->id], $user)))
        ->toBe('Screenshot not found.');

    $ghost = createMcpReport($user);
    app(Screenshot::class)->create(['id' => (string) Str::uuid(), 'report_id' => $ghost->id, 'path' => 'ghost.png']);
    expect(mcpToolText(mcpToolRun(ScreenshotGetTool::class, ['report' => $ghost->id], $user)))
        ->toContain('missing from the screenshots directory');

    $traversal = createMcpReport($user);
    app(Screenshot::class)->create(['id' => (string) Str::uuid(), 'report_id' => $traversal->id, 'path' => '../escape.png']);
    expect(mcpToolText(mcpToolRun(ScreenshotGetTool::class, ['report' => $traversal->id], $user)))
        ->toContain('must be a plain file name');
});

it('the screenshot size gate inlines small and references large', function () {
    $user = makeMcpCallerUser('developer');
    $report = createMcpReport($user);

    app(Screenshot::class)->create([
        'id' => (string) Str::uuid(),
        'report_id' => $report->id,
        'path' => newScreenshotFilePath(),
    ]);

    $inline = mcpToolRun(ScreenshotGetTool::class, ['report' => $report->id], $user);

    expect($inline->content())->toBeInstanceOf(Image::class);
    expect($inline->content()->toArray()['data'])->toBe(base64_encode('binary-bytes'));
    expect($inline->content()->toArray()['mimeType'])->toBe('image/png');

    ensureRedlineDirectories();
    $bigPath = 'big-'.Str::uuid().'.png';
    file_put_contents(
        storage_path('app/redline/screenshots').DIRECTORY_SEPARATOR.$bigPath,
        str_repeat("\0", 1024 * 1024 * 3),
    );

    $big = createMcpReport($user);
    app(Screenshot::class)->create(['id' => (string) Str::uuid(), 'report_id' => $big->id, 'path' => $bigPath]);

    $reference = mcpToolPayload(mcpToolRun(ScreenshotGetTool::class, ['report' => $big->id], $user));

    @unlink(storage_path('app/redline/screenshots').DIRECTORY_SEPARATOR.$bigPath);
    expect($reference['screenshot']['bytes'])->toBe(1024 * 1024 * 3);
    expect($reference['screenshot']['url'])->toContain('/binary');
    expect($reference['note'])->toContain('inline threshold');
});

it('get comments lists oldest first with authors in the envelope', function () {
    $user = makeMcpCallerUser('developer');
    $reporter = makeMcpCallerUser('reporter');
    $report = createMcpReport($user);
    app(Comment::class)->create(['report_id' => $report->id, 'author_id' => $user->id, 'body' => 'first']);
    app(Comment::class)->create(['report_id' => $report->id, 'author_id' => $reporter->id, 'body' => 'second']);
    app(Comment::class)->create(['report_id' => $report->id, 'author_id' => $user->id, 'body' => 'third']);

    // SQLite timestamps carry second resolution, so whole-minute distinct
    // stamps keep the created_at order deterministic.
    $byDate = ['first' => '2020-01-01 00:00:00', 'second' => '2020-01-02 00:00:00', 'third' => '2020-01-03 00:00:00'];
    foreach ($byDate as $body => $stamp) {
        app(Comment::class)->where('report_id', $report->id)->where('body', $body)->update(['created_at' => $stamp]);
    }

    $page = mcpToolPayload(mcpToolRun(CommentListTool::class, ['report' => $report->id, 'per_page' => 2], $user));

    expect(array_column($page['data'], 'body'))->toBe(['first', 'second']);
    expect($page['data'][0]['author']['id'])->toBe($user->id);
    expect($page['meta']['total'])->toBe(3);
    expect($page['meta']['per_page'])->toBe(2);
    expect($page['meta']['last_page'])->toBe(2);

    $secondPage = mcpToolPayload(
        mcpToolRun(CommentListTool::class, ['report' => $report->id, 'per_page' => 2, 'page' => 2], $user),
    );

    expect(array_column($secondPage['data'], 'body'))->toBe(['third']);
    expect(mcpToolText(mcpToolRun(CommentListTool::class, ['report' => $report->id, 'per_page' => 'bogus'], $user)))
        ->toContain('per_page must be an integer between 1 and 50.');
});

it('user list rows are compact and email is developer only', function () {
    $developer = makeMcpCallerUser('developer');
    makeMcpCallerUser('reporter');

    $developerRows = mcpToolPayload(mcpToolRun(UserListTool::class, [], $developer))['data'];
    $developerRow = collect($developerRows)->firstWhere('name', $developer->name);

    expect($developerRow['id'])->toBe($developer->id)
        ->and($developerRow['role'])->toBe('developer')
        ->and($developerRow['email'])->toBe($developer->email);
});

it('user list hides the email from non-developers', function () {
    $reporter = makeMcpCallerUser('reporter');

    $rows = mcpToolPayload(mcpToolRun(UserListTool::class, [], $reporter))['data'];
    $row = collect($rows)->firstWhere('name', $reporter->name);

    expect($row)->not->toHaveKey('email')
        ->and($row['role'])->toBe('reporter');
});

it('user list paginates and caps per_page at fifty', function () {
    $developer = makeMcpCallerUser('developer');

    $listing = mcpToolPayload(mcpToolRun(UserListTool::class, ['per_page' => 999], $developer));

    expect($listing['data'])->not->toBeEmpty()
        ->and($listing['meta']['per_page'])->toBe(50);
});

it('user list names an unauthenticated caller instead of matching silently', function () {
    makeMcpCallerUser('reporter');

    $response = mcpToolRun(UserListTool::class, [], null);

    expect($response->isError())->toBeTrue()
        ->and(mcpToolText($response))->toBe('No authenticated Redline user on this request.');
});

it('user list passes the caller role gate like the HTTP list', function () {
    // The list endpoint is open to every authenticated approved identity, and
    // the tool inherits exactly that gate from UserActions::list - so a
    // reporter gets rows while a guest identity is refused with the shared
    // forbidden vocabulary, never a 500.
    $reporter = makeMcpCallerUser('reporter');

    expect(mcpToolPayload(mcpToolRun(UserListTool::class, [], $reporter))['data'])
        ->toBeArray();

    config(['redline.auth.guest' => true]);

    $guest = app(User::class)->firstOrCreate(
        ['email' => 'guest@redline.local'],
        ['name' => 'Guest', 'role' => Role::GUEST],
    );

    $response = mcpToolRun(UserListTool::class, [], $guest);

    expect($response->isError())->toBeTrue()
        ->and(mcpToolText($response))->toBe('Forbidden.');
});

it('comment list shares the HTTP list gate instead of querying behind it', function () {
    // The rows come from CommentActions::page(), so the tool inherits the same
    // LIST gate the HTTP list runs: a reporter gets rows, a guest identity is
    // refused with the shared forbidden vocabulary - never a 500, never rows.
    $reporter = makeMcpCallerUser('reporter');
    $report = createMcpReport($reporter);

    app(Comment::class)->create(['report_id' => $report->id, 'author_id' => $reporter->id, 'body' => 'hi']);

    expect(mcpToolPayload(mcpToolRun(CommentListTool::class, ['report' => $report->id], $reporter))['data'])
        ->toHaveCount(1);

    config(['redline.auth.guest' => true]);

    $guest = app(User::class)->firstOrCreate(
        ['email' => 'guest@redline.local'],
        ['name' => 'Guest', 'role' => Role::GUEST],
    );

    $response = mcpToolRun(CommentListTool::class, ['report' => $report->id], $guest);

    expect($response->isError())->toBeTrue()
        ->and(mcpToolText($response))->toBe('Forbidden.');
});

it('screenshot get shares the HTTP view gate instead of reading the row unguarded', function () {
    $developer = makeMcpCallerUser('developer');
    $report = createMcpReport($developer);

    app(Screenshot::class)->create([
        'id' => (string) Str::uuid(),
        'report_id' => $report->id,
        'path' => newScreenshotFilePath(),
    ]);

    expect(mcpToolRun(ScreenshotGetTool::class, ['report' => $report->id], $developer)->isError())->toBeFalse();

    config(['redline.auth.guest' => true]);

    $guest = app(User::class)->firstOrCreate(
        ['email' => 'guest@redline.local'],
        ['name' => 'Guest', 'role' => Role::GUEST],
    );

    expect(mcpToolText(mcpToolRun(ScreenshotGetTool::class, ['report' => $report->id], $guest)))
        ->toBe('Forbidden.');
});
