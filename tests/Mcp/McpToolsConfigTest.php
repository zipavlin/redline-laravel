<?php

namespace Zipavlin\Redline\Tests\Mcp;

use DateTime;
use Laravel\Mcp\Server\Tool;
use RuntimeException;
use Zipavlin\Redline\Mcp\RedlineServer;
use Zipavlin\Redline\Mcp\Tools\ReportGetTool;
use Zipavlin\Redline\Mcp\Tools\ReportListTool;

/**
 * The effective MCP tool list: code-level DEFAULT_TOOLS (the read tools
 * plus the three Actions-driven write tools) plus whatever the host's
 * published redline.mcp.tools adds, merged UNDER the defaults - a null,
 * partial, or stale published config can never blank the list.
 */
const DEFAULT_NAMES = [
    'report_list',
    'report_get',
    'screenshot_get',
    'comment_list',
    'user_list',
    'report_create',
    'comment_create',
    'report_update_status',
];

it('the default tool list is exactly the eight shipped tools', function () {
    expect(mcpToolsListed())->toBe(DEFAULT_NAMES);
});

it('null and non-array configs degrade to the defaults', function (mixed $value) {
    config()->set('redline.mcp.tools', $value);

    expect(mcpToolsListed())->toBe(DEFAULT_NAMES);
})->with([
    'null' => null,
    'string' => 'every-default-must-survive',
    'true' => true,
    'integer' => 5,
]);

it('a configured tool appends after the defaults', function () {
    config()->set('redline.mcp.tools', [DemoExtraTool::class]);

    expect(mcpToolsListed())->toBe([...DEFAULT_NAMES, 'demo_extra']);
});

it('a stale config echoing a default deduplicates', function () {
    config()->set('redline.mcp.tools', [
        ReportListTool::class,
        DemoExtraTool::class,
    ]);

    expect(mcpToolsListed())->toBe([...DEFAULT_NAMES, 'demo_extra']);
});

it('the merge is order stable and dedupes defaults first', function () {
    $merged = RedlineServer::mergeToolLists(
        [ReportListTool::class, ReportGetTool::class],
        [ReportGetTool::class, DemoExtraTool::class],
    );

    expect($merged)->toBe([
        ReportListTool::class,
        ReportGetTool::class,
        DemoExtraTool::class,
    ]);
});

it('a bad tool entry is a loud config error', function () {
    config()->set('redline.mcp.tools', ['Zipavlin\Redline\Vendors\Gone\OldTool']);

    expect(fn () => RedlineServer::toolClasses())->toThrow(
        RuntimeException::class,
        'redline.mcp.tools entries must be existing tool class strings, got',
    );
});

it('a non tool entry is a loud config error', function () {
    config()->set('redline.mcp.tools', [DateTime::class]);

    expect(fn () => RedlineServer::toolClasses())->toThrow(
        RuntimeException::class,
        'redline.mcp.tools entries must extend Laravel\Mcp\Server\Tool',
    );
});

it('the abstract Tool base class itself is a loud config error', function () {
    config()->set('redline.mcp.tools', [Tool::class]);

    expect(fn () => RedlineServer::toolClasses())->toThrow(
        RuntimeException::class,
        'redline.mcp.tools entries must extend Laravel\Mcp\Server\Tool',
    );
});

it('a map keyed config merges by value', function () {
    config()->set('redline.mcp.tools', ['demo_extra' => DemoExtraTool::class]);

    expect(mcpToolsListed())->toBe([...DEFAULT_NAMES, 'demo_extra']);
});
