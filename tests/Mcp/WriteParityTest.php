<?php

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Zipavlin\Redline\Mail\NewReportMail;
use Zipavlin\Redline\Mcp\Tools\CommentCreateTool;
use Zipavlin\Redline\Mcp\Tools\ReportCreateTool;
use Zipavlin\Redline\Mcp\Tools\ReportUpdateStatusTool;
use Zipavlin\Redline\Models\Comment;
use Zipavlin\Redline\Models\Report;
use Zipavlin\Redline\Models\User;
use Zipavlin\Redline\Support\HtmlSanitizer;

/**
 * The write-path drift harness (REDLI-51): the SAME payloads through the HTTP
 * endpoints and the MCP write tools must produce IDENTICAL persisted state.
 * Not a smoke test - it exists to fail loudly the day one of the two surfaces
 * quietly stops matching the shared Actions layer.
 */
beforeEach(function () {
    ensureRedlineDirectories();
    config(['mail.default' => 'smtp']); // let notifyNewReport() actually fire
});

/**
 * Authorization headers for a SPECIFIC user (unlike the Pest authHeaders()
 * helper, which always mints a fresh one - parity runs reuse one caller).
 *
 * @return array<string, string>
 */
function bearerFor(User $user): array
{
    return ['Authorization' => 'Bearer '.makeAuthToken($user->refresh()->toArray())];
}

/**
 * The minimal write payload used by the parity fixtures.
 *
 * @return array<string, mixed>
 */
function parityPayload(array $overrides = []): array
{
    return array_merge([
        'url' => 'https://staging.example.com/parity',
        'location' => '/parity',
        'body' => 'Parity fixture.',
        'pin' => ['x' => 34.5, 'y' => 62.1],
    ], $overrides);
}

function membersOf(string $reportId): array
{
    return DB::connection('redline')
        ->table('redline_members')
        ->where('report_id', $reportId)
        ->pluck('user_id')
        ->all();
}

// ---------------------------------------------------------------------------
// report_create vs POST /report
// ---------------------------------------------------------------------------

it('persists an identical sanitized report and notification through HTTP and MCP', function () {
    Mail::fake();
    makeMcpCallerUser('admin'); // a notification recipient must exist

    $httpAuthor = makeMcpCallerUser('developer');
    $mcpAuthor = makeMcpCallerUser('developer');

    $body = '<p>Broken <script>alert(1)</script><strong>lens</strong>'
        .'<span data-type="mention" data-id="forged" onmouseover="x()">mention</span>';

    $payload = fn () => parityPayload(['body' => $body, 'priority' => 'critical']);

    // The identical payload, first through HTTP ...
    $http = $this->postJson('/redline/api/report', $payload(), bearerFor($httpAuthor))
        ->assertStatus(201)
        ->json();

    // ... then the identical payload through the MCP tool.
    $mcp = mcpToolPayload(mcpToolRun(ReportCreateTool::class, $payload(), $mcpAuthor));

    $httpRow = app(Report::class)->find($http['id']);
    $mcpRow = app(Report::class)->find($mcp['id']);

    $expected = HtmlSanitizer::clean($body);

    foreach ([$httpRow, $mcpRow] as $row) {
        expect($row->body)->toBe($expected)
            ->and($row->status)->toBe('new')
            ->and($row->priority)->toBe('critical')
            ->and($row->location)->toBe('/parity')
            ->and($row->pin)->toBe(parityPayload()['pin']);
    }

    expect($httpRow->author_id)->toBe($httpAuthor->id)
        ->and($mcpRow->author_id)->toBe($mcpAuthor->id);

    // The developer notification fired for BOTH paths.
    Mail::assertSent(NewReportMail::class, 2);
});

it('persists the same shape when no pin is sent through either surface', function () {
    $httpAuthor = makeMcpCallerUser('developer');
    $mcpAuthor = makeMcpCallerUser('developer');

    $http = $this->postJson('/redline/api/report', parityPayload(['pin' => null]), bearerFor($httpAuthor))
        ->assertStatus(201)
        ->json();

    $mcp = mcpToolPayload(mcpToolRun(
        ReportCreateTool::class,
        parityPayload(['pin' => null]),
        $mcpAuthor,
    ));

    expect(app(Report::class)->find($http['id'])->pin)->toEqual(
        app(Report::class)->find($mcp['id'])->pin
    );
});

it('groups a widget POST and an MCP call under one derived location', function () {
    Mail::fake();
    $httpCaller = makeMcpCallerUser('reporter');
    $mcpCaller = makeMcpCallerUser('developer');

    // A campaign link: the widget computes location === the derived key.
    $url = 'https://staging.example.com/parity?b=1&a=2&utm_source=x';
    $location = '/parity?a=2&b=1';

    $this->postJson('/redline/api/report', ['url' => $url, 'location' => $location, 'body' => 'b'], bearerFor($httpCaller))
        ->assertStatus(201);

    // The MCP surface sends no location - the server derives it from the url.
    mcpToolPayload(mcpToolRun(ReportCreateTool::class, ['url' => $url, 'body' => 'b'], $mcpCaller));

    $aggregate = $this->getJson('/redline/api/report/aggregate?aggregate[location:count]', bearerFor($mcpCaller))
        ->assertOk()
        ->json('location.count');

    expect($aggregate)->toBe([$location => 2]);
});

// ---------------------------------------------------------------------------
// report_update_status vs PATCH /report/{id}/status
// ---------------------------------------------------------------------------

it('triggers the same acceptor assignment on a noticed -> progress claim', function () {
    $author = makeMcpCallerUser('reporter');
    $actor = makeMcpCallerUser('developer');

    $viaHttp = app(Report::class)->create([
        'author_id' => $author->id, ...parityPayload(), 'status' => 'noticed',
    ]);
    $viaMcp = app(Report::class)->create([
        'author_id' => $author->id, ...parityPayload(), 'status' => 'noticed',
    ]);

    $this->patchJson("/redline/api/report/{$viaHttp->id}/status", ['status' => 'progress'], bearerFor($actor))
        ->assertStatus(200);

    mcpToolRun(ReportUpdateStatusTool::class, ['report' => $viaMcp->id, 'status' => 'progress'], $actor);

    expect(membersOf($viaHttp->id))->toBe([$actor->id])
        ->and(membersOf($viaMcp->id))->toBe(membersOf($viaHttp->id));
});

it('does not assign anyone when neither surface uses a claim transition', function () {
    $author = makeMcpCallerUser('reporter');
    $actor = makeMcpCallerUser('developer');

    $viaHttp = app(Report::class)->create([
        'author_id' => $author->id, ...parityPayload(), 'status' => 'resolved',
    ]);
    $viaMcp = app(Report::class)->create([
        'author_id' => $author->id, ...parityPayload(), 'status' => 'resolved',
    ]);

    $this->patchJson("/redline/api/report/{$viaHttp->id}/status", ['status' => 'progress'], bearerFor($actor))
        ->assertStatus(200);

    mcpToolRun(ReportUpdateStatusTool::class, ['report' => $viaMcp->id, 'status' => 'progress'], $actor);

    expect(membersOf($viaHttp->id))->toBe([])
        ->and(membersOf($viaMcp->id))->toBe([]);
});

it('matches the HTTP per-role transition matrix', function (string $role, string $from, string $to, bool $own) {
    $caller = makeMcpCallerUser($role);
    $authorId = $own ? $caller->id : makeMcpCallerUser('reporter')->id;

    // Two identical reports - one advances over HTTP, one over MCP - so each
    // surface always starts from the same $from state.
    $viaHttp = app(Report::class)->create([
        'author_id' => $authorId, ...parityPayload(), 'status' => $from,
    ]);
    $viaMcp = app(Report::class)->create([
        'author_id' => $authorId, ...parityPayload(), 'status' => $from,
    ]);

    $httpStatus = $this->patchJson(
        "/redline/api/report/{$viaHttp->id}/status",
        ['status' => $to],
        bearerFor($caller),
    )->status();

    $tool = mcpToolRun(ReportUpdateStatusTool::class, ['report' => $viaMcp->id, 'status' => $to], $caller);

    expect($tool->isError())->toBe($httpStatus !== 200)
        ->and(mcpToolText($tool))->not->toBe('')
        ->and(app(Report::class)->find($viaHttp->id)->status)
        ->toBe($httpStatus === 200 ? $to : $from)
        ->and(app(Report::class)->find($viaMcp->id)->status)
        ->toBe($tool->isError() ? $from : $to);
})->with([
    // [role, from, to, caller owns the report]
    'developer notices a fresh report' => ['developer', 'new', 'noticed', true],
    'developer skips from fresh to progress' => ['developer', 'new', 'progress', true],
    'developer claims a noticed report' => ['developer', 'noticed', 'progress', true],
    'developer resolves from noticed' => ['developer', 'noticed', 'resolved', true],
    'developer confirms resolved (reporter action)' => ['developer', 'resolved', 'done', true],
    'developer reopens resolved' => ['developer', 'resolved', 'progress', true],
    'reporter reopens their own' => ['reporter', 'resolved', 'progress', true],
    'reporter confirms their own' => ['reporter', 'resolved', 'done', true],
    'reporter drives a fresh report' => ['reporter', 'new', 'noticed', true],
    'reporter confirms an unresolved own' => ['reporter', 'progress', 'done', true],
    'reporter claims a strangers report' => ['reporter', 'noticed', 'progress', false],
    'admin acts like a developer' => ['admin', 'new', 'noticed', true],
]);

// ---------------------------------------------------------------------------
// comment_create vs POST /report/{id}/comment
// ---------------------------------------------------------------------------

it('stores identically sanitized comments through HTTP and MCP', function () {
    config(['redline.features' => ['comments']]);

    $author = makeMcpCallerUser('developer');
    $report = app(Report::class)->create(['author_id' => $author->id, ...parityPayload()]);

    $body = 'Look at <b>bold text</b> and <em>this emphasis</em> then <script>no()</script>';

    $http = $this->postJson("/redline/api/report/{$report->id}/comment", ['body' => $body], bearerFor($author))
        ->assertStatus(201)
        ->json();

    $mcp = mcpToolPayload(mcpToolRun(CommentCreateTool::class, ['report' => $report->id, 'body' => $body], $author));

    expect($mcp['id'])->not->toBe($http['id']); // two comments, identical rules

    $rows = app(Comment::class)->whereIn('id', [$http['id'], $mcp['id']])->get();

    foreach ($rows as $row) {
        expect($row->report_id)->toBe($report->id)
            ->and($row->author_id)->toBe($author->id)
            ->and($row->body)->toBe(HtmlSanitizer::clean($body));
    }
});

it('rejects comments identically when the feature is off', function () {
    config(['redline.features' => ['screenshots']]);

    $user = makeMcpCallerUser('developer');
    $author = makeMcpCallerUser('reporter');
    $report = app(Report::class)->create(['author_id' => $author->id, ...parityPayload()]);

    $httpStatus = $this->postJson(
        "/redline/api/report/{$report->id}/comment",
        ['body' => 'hello'],
        bearerFor($user),
    )->status();

    $tool = mcpToolRun(CommentCreateTool::class, ['report' => $report->id, 'body' => 'hello'], $user);

    expect($httpStatus)->toBe(403)
        ->and($tool->isError())->toBeTrue()
        ->and(mcpToolText($tool))->toBe('Feature disabled.')
        ->and(app(Comment::class)->where('report_id', $report->id)->count())->toBe(0);
});
