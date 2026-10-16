<?php

namespace Zipavlin\Redline\Mcp\Tools;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Http\Request as HttpRequest;
use InvalidArgumentException;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;
use Zipavlin\Redline\Actions\CommentActions;
use Zipavlin\Redline\Mcp\Concerns\CanMapActionErrors;
use Zipavlin\Redline\Mcp\Concerns\CanResolveReport;

/**
 * `comment_create` - append a comment to a report through the shared Actions
 * layer, mirroring the HTTP POST gate exactly (comments feature, CREATE role
 * gate, body validation).
 */
class CommentCreateTool extends Tool
{
    use CanMapActionErrors;
    use CanResolveReport;

    public function __construct(private readonly CommentActions $actions) {}

    protected string $name = 'comment_create';

    protected string $title = 'Create Comment';

    public function description(): string
    {
        return <<<'TEXT'
            Add a comment to one report. Body is sanitized with the same HTML
            allowlist as the HTTP API. The comments feature must be enabled
            and the caller's role must be allowed to comment (same gates as
            HTTP POST /report/{id}/comment - a disabled feature answers
            "Feature disabled.").
            Address the report with either [report] (UUID) or [url].
            TEXT;
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'report' => $schema->string()
                ->description('Report id (UUID). Supply report or url - never both.'),
            'url' => $schema->string()
                ->description('A link or address whose trailing UUID is the report id, e.g. a Redline dashboard link ending in ?redline-report=<uuid>. Supply report or url - never both.'),
            'body' => $schema->string()
                ->required()
                ->description('The comment text.'),
        ];
    }

    public function handle(Request $mcp, HttpRequest $incoming): Response
    {
        $user = $this->caller($incoming);

        if ($user === null) {
            return $this->unauthenticatedResponse();
        }

        $body = $mcp->get('body');

        try {
            $report = $this->resolveReport($mcp->get('report'), $mcp->get('url'));
        } catch (InvalidArgumentException $exception) {
            return Response::error($exception->getMessage());
        }

        return $this->mapFailures(fn (): Response => Response::json([
            'id' => $this->actions->create($user, $report, ['body' => $body])->id,
        ]));
    }
}
