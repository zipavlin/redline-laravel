<?php

namespace Zipavlin\Redline\Tests\Mcp;

use Zipavlin\Redline\Models\Report;

/**
 * The real wire path for MCP tools: a POST to {redline.path}/api/mcp with an
 * `rlm_` bearer, JSON-RPC tools/call resolved by laravel/mcp's own machinery
 * (ToolInvoker -> Container::call([$tool, 'handle']), binding the incoming
 * HTTP request and the JSON-RPC request from the container). The hand-built
 * mcpToolRun() helper exercises handle() directly; this proves the container
 * resolution itself - the silent-failure shape (every tool answering "No
 * authenticated Redline user") cannot hide here.
 *
 * Coordinate source: contracts/mcp.md section 3; JSON-RPC shape matches the
 * initializeMcpBody() helper used for the handshake.
 */
it('the wire tools/list advertises the model-first tool names', function () {
    $token = makeApiToken()['token'];

    $response = $this->postJson('/redline/api/mcp', [
        'jsonrpc' => '2.0',
        'id' => 4,
        'method' => 'tools/list',
        'params' => [],
    ], ['Authorization' => 'Bearer '.$token]);

    $response->assertOk();

    $names = array_map(
        fn (array $entry) => $entry['name'],
        (array) $response->json('result.tools'),
    );

    expect($names)->toBe([
        'report_list',
        'report_get',
        'screenshot_get',
        'comment_list',
        'user_list',
        'report_create',
        'comment_create',
        'report_update_status',
    ]);
});

it('report_list answers through the real vendor invoker', function () {
    ['user' => $user, 'token' => $token] = makeApiToken();
    createMcpReport($user, ['location' => '/wire-list']);

    $response = $this->postJson('/redline/api/mcp', [
        'jsonrpc' => '2.0',
        'id' => 2,
        'method' => 'tools/call',
        'params' => ['name' => 'report_list', 'arguments' => ['location' => '/wire-list']],
    ], ['Authorization' => 'Bearer '.$token]);

    $response->assertOk()
        ->assertJsonPath('jsonrpc', '2.0')
        ->assertJsonPath('id', 2)
        ->assertJsonPath('result.isError', false);

    $listing = json_decode((string) $response->json('result.content.0.text'), true, 512, JSON_THROW_ON_ERROR);

    expect($listing['data'])->toHaveCount(1);
    expect($listing['data'][0]['location'])->toBe('/wire-list');
    expect($listing['data'][0]['author']['id'])->toBe($user->refresh()->id);
});

it('report_create writes through the real vendor invoker and persists', function () {
    ['user' => $user, 'token' => $token] = makeApiToken();

    $response = $this->postJson('/redline/api/mcp', [
        'jsonrpc' => '2.0',
        'id' => 3,
        'method' => 'tools/call',
        'params' => ['name' => 'report_create', 'arguments' => [
            'url' => 'https://staging.example.com/orders/3',
            'body' => 'Wire path body',
        ]],
    ], ['Authorization' => 'Bearer '.$token]);

    $response->assertOk()
        ->assertJsonPath('result.isError', false);

    $created = json_decode((string) $response->json('result.content.0.text'), true, 512, JSON_THROW_ON_ERROR);

    expect($created['url'])->toBe('https://staging.example.com/orders/3');

    /** @var Report $report */
    $report = app(Report::class)->find($created['id']);

    expect($report->id)->toBe($created['id'])
        ->and($report->author_id)->toBe($user->id)
        ->and($report->status)->toBe('new')
        ->and($report->body)->toBe('Wire path body')
        ->and($report->location)->toBe('/orders/3');
});
