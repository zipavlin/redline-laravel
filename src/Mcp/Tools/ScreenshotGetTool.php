<?php

namespace Zipavlin\Redline\Mcp\Tools;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Http\Request as HttpRequest;
use InvalidArgumentException;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;
use Zipavlin\Redline\Actions\ScreenshotActions;
use Zipavlin\Redline\Mcp\Concerns\CanMapActionErrors;
use Zipavlin\Redline\Mcp\Concerns\CanResolveReport;
use Zipavlin\Redline\Models\Screenshot;
use Zipavlin\Redline\Support\MediaFile;

/**
 * `screenshot_get` - the report's one screenshot, inline as image content when
 * it is at or under the ~2 MB inline threshold, and otherwise as metadata plus
 * the authenticated binary URL: a 5 MB PNG base64-encodes to ~6.7 MB of agent
 * context, so larger images are never inlined.
 *
 * The read, its VIEW gate, the file-name guard and the path composition all
 * come from ScreenshotActions: only the transport answer (inline image vs
 * metadata) is the tool's own.
 */
#[IsReadOnly]
class ScreenshotGetTool extends Tool
{
    use CanMapActionErrors;
    use CanResolveReport;

    /** An image at or under this many bytes is returned inline (the contract's "~2 MB"). */
    private const INLINE_MAX_BYTES = 2 * 1024 * 1024;

    public function __construct(private readonly ScreenshotActions $actions) {}

    protected string $name = 'screenshot_get';

    protected string $title = 'Get Screenshot';

    public function description(): string
    {
        return <<<'TEXT'
            The report's screenshot as image content - unless it exceeds the
            ~2 MB inline threshold, in which case the tool returns the
            screenshot metadata (id, byte size) plus the authenticated binary
            URL to fetch separately with the same bearer token.
            TEXT;
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'report' => $schema->string()
                ->description('Report id (UUID). Supply report or url - never both.'),
            'url' => $schema->string()
                ->description('A link or address whose trailing UUID is the report id, e.g. ?redline-report=<uuid>. Supply report or url - never both.'),
        ];
    }

    /**
     * @throws \JsonException
     */
    public function handle(Request $mcp, HttpRequest $incoming): Response
    {
        try {
            $report = $this->resolveReport($mcp->get('report'), $mcp->get('url'));
        } catch (InvalidArgumentException $exception) {
            return Response::error($exception->getMessage());
        }

        $user = $this->caller($incoming);

        if ($user === null) {
            return $this->unauthenticatedResponse();
        }

        return $this->mapFailures(function () use ($user, $report): Response {
            $screenshot = $this->actions->get($user, $report);

            if ($screenshot === null) {
                return Response::error('Screenshot not found.');
            }

            $plainName = MediaFile::plainName($screenshot->path);

            if ($plainName === null) {
                return Response::error(
                    'Screenshot path ['.$screenshot->path.'] is invalid - it must be a plain file name inside the screenshot directory.'
                );
            }

            $file = $this->actions->absolutePath($plainName);

            if (! is_file($file)) {
                return Response::error(
                    'Screenshot not found - the stored file ['.$plainName.'] is missing from the screenshots directory.'
                );
            }

            return $this->inlineOrMetadata($screenshot, $file);
        });
    }

    /**
     * Small images travel inline; anything over the threshold comes back as
     * metadata plus the authenticated URL instead of megabytes of context.
     */
    private function inlineOrMetadata(Screenshot $screenshot, string $file): Response
    {
        $bytes = (int) filesize($file);

        if ($bytes <= self::INLINE_MAX_BYTES) {
            return Response::image((string) file_get_contents($file));
        }

        return Response::json([
            'screenshot' => [
                'id' => $screenshot->id,
                'bytes' => $bytes,
                'url' => $screenshot->url,
            ],
            'note' => 'The image exceeds the ~2 MB inline threshold; base64-encoding it here would bloat the agent context. Fetch it from the [screenshot.url] value with the same bearer token.',
        ]);
    }
}
