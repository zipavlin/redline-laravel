<?php

use Zipavlin\Redline\Mcp\Tools\CommentCreateTool;
use Zipavlin\Redline\Mcp\Tools\ReportCreateTool;
use Zipavlin\Redline\Mcp\Tools\ReportUpdateStatusTool;
use Zipavlin\Redline\Models\Comment;
use Zipavlin\Redline\Models\Report;
use Zipavlin\Redline\Support\HtmlSanitizer;

/**
 * Milestone 2 write-tool matrix, driven through each tool's public handle()
 * (the method the MCP invoker resolves). persisted-state semantics shared
 * with HTTP live in WriteParityTest; this file covers each tool's own shape:
 * validation, feature gate, addressing and the actionable error texts.
 */
beforeEach(function () {
    ensureRedlineDirectories();
});

// ---------------------------------------------------------------------------
// report_create
// ---------------------------------------------------------------------------

it('report_create files a report for its user and echoes id + url', function () {
    $user = makeMcpCallerUser('developer');

    $payload = mcpToolPayload(mcpToolRun(ReportCreateTool::class, [
        'url' => 'https://staging.example.com/checkout?page=2',
        'body' => 'The <strong>total</strong> is wrong<script>steal()</script>',
        'priority' => 'high',
        'pin' => ['x' => 25, 'y' => 75, 'scroll_y' => 120],
        'element' => '[data-test="place-order"]',
    ], $user));

    $report = app(Report::class)->find($payload['id']);

    expect($payload['url'])->toBe('https://staging.example.com/checkout?page=2')
        ->and($report->author_id)->toBe($user->id)
        ->and($report->status)->toBe('new')
        ->and($report->priority)->toBe('high')
        ->and($report->location)->toBe('/checkout?page=2')
        ->and($report->body)->toBe(HtmlSanitizer::clean('The <strong>total</strong> is wrong<script>steal()</script>'))
        ->and($report->pin)->toBe(['x' => 25, 'y' => 75, 'scroll_y' => 120])
        ->and($report->element)->toBe('[data-test="place-order"]');
});

it('report_create falls back to normal priority', function () {
    $user = makeMcpCallerUser('developer');
    $payload = mcpToolPayload(mcpToolRun(ReportCreateTool::class, [
        'url' => 'https://staging.example.com/', 'body' => 'x',
    ], $user));

    expect(app(Report::class)->find($payload['id'])->priority)->toBe('normal')
        ->and(app(Report::class)->find($payload['id'])->location)->toBe('/');
});

it('report_create validates the required fields actionably', function () {
    $user = makeMcpCallerUser('developer');

    $none = mcpToolRun(ReportCreateTool::class, [], $user);

    expect($none->isError())->toBeTrue();

    // `location` is not an input - only url and body are required. Both
    // missing messages surface together (store rules run as one block).
    expect(mcpToolText(mcpToolRun(ReportCreateTool::class, ['body' => 'x'], $user)))
        ->toContain('The url field is required.');
    expect(mcpToolText(mcpToolRun(ReportCreateTool::class, ['url' => 'https://x.y'], $user)))
        ->toContain('The body field is required.');
});

it('report_create rejects a non-absolute url', function (string $url) {
    $user = makeMcpCallerUser('developer');

    $response = mcpToolRun(ReportCreateTool::class, ['url' => $url, 'body' => 'b'], $user);

    expect(mcpToolText($response))
        ->toBe('The url must be an absolute http(s) address, e.g. https://example.com/page.');
    expect(app(Report::class)->count())->toBe(0);
})->with([
    'site-relative' => '/checkout',
    'ftp scheme' => 'ftp://x',
]);

it('report_create derives the page grouping key from the url', function () {
    $user = makeMcpCallerUser('developer');

    $payload = mcpToolPayload(mcpToolRun(ReportCreateTool::class, [
        'url' => 'https://x.test/checkout?b=1&a=2&utm_source=x#frag',
        'body' => 'b',
    ], $user));

    expect(app(Report::class)->find($payload['id'])->location)->toBe('/checkout?a=2&b=1');
});

it('report_create ignores a stray agent-supplied location', function () {
    $user = makeMcpCallerUser('developer');

    $payload = mcpToolPayload(mcpToolRun(ReportCreateTool::class, [
        'url' => 'https://x.test/settings',
        'body' => 'b',
        'location' => '/ (hero description)',
    ], $user));

    expect(app(Report::class)->find($payload['id'])->location)->toBe('/settings');
});

it('report_create schema no longer advertises a location input', function () {
    $properties = app(ReportCreateTool::class)->toArray()['inputSchema']['properties'];

    expect(array_keys($properties))->not->toContain('location')
        ->and(array_keys($properties))->toContain('url', 'body', 'priority', 'pin', 'element');
});

it('report_create rejects the over-limit url', function () {
    $user = makeMcpCallerUser('developer');

    $response = mcpToolRun(ReportCreateTool::class, [
        'url' => 'https://staging.example.com/'.str_repeat('a', 2048),
        'body' => 'b',
    ], $user);

    expect($response->isError())->toBeTrue();
    expect(mcpToolText($response))->toContain('2048');
});

it('report_create rejects an unknown priority value', function () {
    $user = makeMcpCallerUser('developer');

    $response = mcpToolRun(ReportCreateTool::class, [
        'url' => 'https://staging.example.com/', 'body' => 'b', 'priority' => 'urgent',
    ], $user);

    expect($response->isError())->toBeTrue();
    expect(mcpToolText($response))->toBe('The selected priority is invalid.');
    expect(app(Report::class)->count())->toBe(0);
});

it('report_create requires both pin coordinates when a pin is sent', function () {
    $user = makeMcpCallerUser('developer');

    $missingY = mcpToolRun(ReportCreateTool::class, [
        'url' => 'https://staging.example.com/', 'body' => 'b', 'pin' => ['x' => 10],
    ], $user);

    $missingX = mcpToolRun(ReportCreateTool::class, [
        'url' => 'https://staging.example.com/', 'body' => 'b', 'pin' => ['y' => 10],
    ], $user);

    $outOfRange = mcpToolRun(ReportCreateTool::class, [
        'url' => 'https://staging.example.com/', 'body' => 'b', 'pin' => ['x' => 150, 'y' => 10],
    ], $user);

    expect(mcpToolText($missingY))->toContain('pin.y')
        ->and(mcpToolText($missingX))->toContain('pin.x')
        ->and(mcpToolText($outOfRange))->toContain('greater than');
    expect(app(Report::class)->count())->toBe(0);
});

it('report_create names the caller instead of silently writing nothing', function () {
    $response = mcpToolRun(ReportCreateTool::class, [
        'url' => 'https://staging.example.com/', 'body' => 'b',
    ], null);

    expect($response->isError())->toBeTrue();
    expect(mcpToolText($response))->toBe('No authenticated Redline user on this request.');
});

// ---------------------------------------------------------------------------
// comment_create
// ---------------------------------------------------------------------------

it('comment_create appends a sanitized comment for its user', function () {
    config(['redline.features' => ['comments']]);

    $user = makeMcpCallerUser('reporter');
    $report = createMcpReport();

    $payload = mcpToolPayload(mcpToolRun(CommentCreateTool::class, [
        'report' => $report->id,
        'body' => 'Details in <em>this</em> link <script>boom()</script>',
    ], $user));

    $comment = app(Comment::class)->find($payload['id']);

    expect($comment->report_id)->toBe($report->id)
        ->and($comment->author_id)->toBe($user->id)
        ->and($comment->body)->toBe(HtmlSanitizer::clean('Details in <em>this</em> link <script>boom()</script>'));
});

it('comment_create rejects a missing body actionably', function () {
    config(['redline.features' => ['comments']]);

    $report = createMcpReport();

    $response = mcpToolRun(CommentCreateTool::class, ['url' => '?redline-report='.$report->id], makeMcpCallerUser());

    expect($response->isError())->toBeTrue();
    expect(mcpToolText($response))->toBe('The body field is required.');
});

it('comment_create answers not found for an unknown report', function () {
    $response = mcpToolRun(
        CommentCreateTool::class,
        ['report' => '00000000-0000-0000-0000-000000000000', 'body' => 'x'],
        makeMcpCallerUser(),
    );

    expect($response->isError())->toBeTrue();
    expect(mcpToolText($response))->toBe('No report found for [00000000-0000-0000-0000-000000000000].');
});

it('comment_create refuses a doubly addressed call', function () {
    $report = createMcpReport();

    $response = mcpToolRun(
        CommentCreateTool::class,
        ['report' => $report->id, 'url' => '?redline-report='.$report->id, 'body' => 'x'],
        makeMcpCallerUser(),
    );

    expect(mcpToolText($response))->toContain('not both');
});

// ---------------------------------------------------------------------------
// report_update_status
// ---------------------------------------------------------------------------

it('report_update_status returns the updated report row', function () {
    $developer = makeMcpCallerUser('developer');
    $report = createMcpReport(null, ['status' => 'noticed']);

    $payload = mcpToolPayload(mcpToolRun(ReportUpdateStatusTool::class, [
        'report' => $report->id, 'status' => 'progress',
    ], $developer));

    expect($payload['status'])->toBe('progress')
        ->and(app(Report::class)->find($report->id)->status)->toBe('progress');
});

it('report_update_status validates an unknown status value as 422-like', function () {
    $report = createMcpReport(null, ['status' => 'noticed']);

    $response = mcpToolRun(ReportUpdateStatusTool::class, [
        'report' => $report->id, 'status' => 'bogus',
    ], makeMcpCallerUser('developer'));

    expect($response->isError())->toBeTrue();
    expect(mcpToolText($response))->toBe('The selected status is invalid.');
    expect(app(Report::class)->find($report->id)->status)->toBe('noticed');
});

it('report_update_status rejects a missing status like HTTP', function () {
    $report = createMcpReport(null, ['status' => 'noticed']);

    $response = mcpToolRun(ReportUpdateStatusTool::class, ['report' => $report->id], makeMcpCallerUser('developer'));

    expect(mcpToolText($response))->toBe('The status field is required.');
});

it('report_update_status rejects an illegal transition and names the legal targets', function () {
    $report = createMcpReport(null, ['status' => 'new']);

    $response = mcpToolRun(ReportUpdateStatusTool::class, [
        'report' => $report->id, 'status' => 'progress',
    ], makeMcpCallerUser('developer'));

    expect($response->isError())->toBeTrue();
    expect(mcpToolText($response))
        ->toBe('Invalid status transition: a new report cannot move to [progress]. Allowed targets for your role: [noticed].');
    expect(app(Report::class)->find($report->id)->status)->toBe('new');
});

it('report_update_status reports no legal target when the role has none', function () {
    $reporter = makeMcpCallerUser('reporter');
    $report = createMcpReport($reporter, ['status' => 'new']);

    $response = mcpToolRun(ReportUpdateStatusTool::class, [
        'report' => $report->id, 'status' => 'done',
    ], $reporter);

    expect($response->isError())->toBeTrue();
    expect(mcpToolText($response))
        ->toBe('Invalid status transition: a new report cannot move to [done], and your role has no legal target here.');
});

it('report_update_status forbids a stranger reporter with the HTTP vocabulary', function () {
    $author = makeMcpCallerUser('reporter');
    $report = createMcpReport($author, ['status' => 'noticed']);

    $response = mcpToolRun(ReportUpdateStatusTool::class, [
        'report' => $report->id, 'status' => 'progress',
    ], makeMcpCallerUser('reporter'));

    expect($response->isError())->toBeTrue();
    expect(mcpToolText($response))->toBe('Forbidden.');
    expect(app(Report::class)->find($report->id)->status)->toBe('noticed');
});

it('report_update_status answers not found for an unknown address', function () {
    $response = mcpToolRun(ReportUpdateStatusTool::class, [
        'report' => 'not-a-uuid', 'status' => 'progress',
    ], makeMcpCallerUser('developer'));

    expect($response->isError())->toBeTrue();
    expect(mcpToolText($response))->toContain('does not address a report');
});

it('report_create rejects an element longer than the contract cap of 512 characters', function () {
    $user = makeMcpCallerUser('developer');

    $response = mcpToolRun(ReportCreateTool::class, [
        'url' => 'https://staging.example.com/checkout',
        'body' => 'The total is wrong.',
        'element' => str_repeat('#a', 257), // 514 characters
    ], $user);

    // The cap is the shared store rule (the same one HTTP POST /report runs),
    // so the contract's "<= 512 characters" is pinned on the write path.
    expect(mcpToolText($response))->toContain('512');
});
