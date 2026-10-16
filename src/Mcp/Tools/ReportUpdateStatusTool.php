<?php

namespace Zipavlin\Redline\Mcp\Tools;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Http\Request as HttpRequest;
use InvalidArgumentException;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;
use Zipavlin\Redline\Actions\ReportActions;
use Zipavlin\Redline\Mcp\Concerns\CanMapActionErrors;
use Zipavlin\Redline\Mcp\Concerns\CanResolveReport;

/**
 * `report_update_status` - move a report along the status lifecycle through
 * the shared Actions layer. A rejected transition answers with the caller's
 * role's allowed targets (MCP contract section 3), not a bare refusal.
 */
class ReportUpdateStatusTool extends Tool
{
    use CanMapActionErrors;
    use CanResolveReport;

    public function __construct(private readonly ReportActions $actions) {}

    protected string $name = 'report_update_status';

    protected string $title = 'Update Report Status';

    public function description(): string
    {
        return <<<'TEXT'
            Move one report along the Redline status lifecycle (the same chain
            and per-role gates as HTTP PATCH /report/{id}/status): a developer
            or admin drives noticed/progress/resolved; a report owner may
            reopen (resolved -> progress) or confirm (resolved -> done). An
            illegal transition answers the statuses your role could still use
            right now - no blind retries needed.

            Accepting (noticed -> progress) automatically records you as a
            member, exactly like the HTTP endpoint. Reports are addressed
            with either [report] (UUID) or [url].
            TEXT;
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'report' => $schema->string()
                ->description('Report id (UUID). Supply report or url - never both.'),
            'url' => $schema->string()
                ->description('A link or address whose trailing UUID is the report id, e.g. ?redline-report=<uuid>. Supply report or url - never both.'),
            'status' => $schema->string()
                ->required()
                ->description('Target status: new|noticed|progress|resolved|done.'),
        ];
    }

    public function handle(Request $mcp, HttpRequest $incoming): Response
    {
        $user = $this->caller($incoming);

        if ($user === null) {
            return $this->unauthenticatedResponse();
        }

        try {
            $report = $this->resolveReport($mcp->get('report'), $mcp->get('url'));
        } catch (InvalidArgumentException $exception) {
            return Response::error($exception->getMessage());
        }

        return $this->mapFailures(fn (): Response => Response::json(
            $this->actions->updateStatus($report, $user, $mcp->get('status'))->toArray()
        ));
    }
}
