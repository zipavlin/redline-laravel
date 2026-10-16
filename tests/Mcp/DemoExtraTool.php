<?php

namespace Zipavlin\Redline\Tests\Mcp;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;

/**
 * A minimal valid tool standing in for a host-added MCP tool in the
 * redline.mcp.tools merge tests - never a code default, so its presence in
 * tools/list proves a published config really appends on top of the defaults.
 */
class DemoExtraTool extends Tool
{
    protected string $name = 'demo_extra';

    public function description(): string
    {
        return 'Test-only tool standing in for a host-added MCP tool.';
    }

    /**
     * @return array<string, mixed>
     */
    public function schema(JsonSchema $schema): array
    {
        return [];
    }

    public function handle(Request $request): Response
    {
        return Response::text('demo');
    }
}
