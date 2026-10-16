<?php

namespace Zipavlin\Redline\Mcp\Tools;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Http\Request as HttpRequest;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;
use Zipavlin\Redline\Actions\ReportActions;
use Zipavlin\Redline\Mcp\Concerns\CanMapActionErrors;
use Zipavlin\Redline\Models\User;
use Zipavlin\Redline\Support\PageLocation;

/**
 * `report_create` - file a bug report through the shared Actions layer.
 *
 * Shape is the HTTP POST /report contract (MCP contract section 3), with two
 * deliberate tightenings: pin.x/pin.y are REQUIRED whenever a pin is given,
 * `location` is not an input - the server derives the page grouping key
 * stored as `location` from `url` - and `element` is capped at 512 characters
 * (the MCP contract's limit; the cap is deliberately NOT on the shared store
 * rules, so POST /report keeps accepting whatever a host's own widget
 * generates).
 * Media (screenshot/video) stays out - agents attach it through the HTTP
 * upload endpoints with the same bearer token.
 */
class ReportCreateTool extends Tool
{
    use CanMapActionErrors;

    /** The MCP contract's element cap (mcp.md); the shared HTTP store rules stay uncapped. */
    private const ELEMENT_MAX_LENGTH = 512;

    public function __construct(private readonly ReportActions $actions) {}

    protected string $name = 'report_create';

    protected string $title = 'Create Report';

    public function description(): string
    {
        return <<<'TEXT'
            File a new bug report. Requires url (the page the bug is on,
            max 2048) and body (the description, sanitized with the same HTML
            allowlist as the HTTP API). Optional: priority, pin and element.

            When reporting a UI element, pass an `element` CSS selector and/or
            a `pin` position; element is preferred when you can inspect the DOM.

            The created report is acknowledged as new and reported to the
            developers exactly like an HTTP submission. There is no
            screenshot/video upload through this tool - attach media later
            through the HTTP upload endpoints with the same bearer token.
            TEXT;
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'url' => $schema->string()
                ->required()
                ->description('Absolute http(s) URL of the page the bug is on, including origin and any query string that defines the page, e.g. https://staging.example.com/settings?tab=billing (max 2048 characters). The server derives the page grouping key from it - do not send a separate location.'),
            'body' => $schema->string()
                ->required()
                ->description('The bug description. HTML is sanitized with the same allowlist as HTTP.'),
            'priority' => $schema->string()
                ->description('Report urgency: low|normal|high|critical. Defaults to normal.'),
            'pin' => $schema->object([
                'x' => $schema
                    ->number()
                    ->required(),
                'y' => $schema
                    ->number()
                    ->required(),
                'prefer_selector' => $schema
                    ->boolean()
                    ->description('When true and `element` resolves on the page, the pin is anchored to that element instead of the raw x/y percentages. Always send true together with `element`.'),
                'scroll_x' => $schema
                    ->number(),
                'scroll_y' => $schema
                    ->number(),
            ])
                ->description(
                    'Optional position of the issue as percentages of the viewport at the time of viewing '
                    .'(x: 0..100 across, y: 0..100 down; both REQUIRED together). Pins are viewport-relative, so '
                    .'they land differently on other screen sizes and scroll positions: use them for areas with '
                    .'no stable element. When you also send `element`, set `prefer_selector` to true so the '
                    .'element is located by its selector and the pin is only a fallback. If you measured the '
                    .'position in a browser, also send scroll_x/scroll_y (the page scroll offsets at '
                    .'measurement time).'
                ),
            'element' => $schema->string()
                ->max(self::ELEMENT_MAX_LENGTH)
                ->description(
                    'CSS selector (document.querySelector syntax) of the affected element, e.g. \'#checkout-button\' '
                    .'or \'form.order-form .submit-row\'. Prefer a short selector that resolves to exactly one element: '
                    .'verify it with document.querySelectorAll(selector).length === 1 in a browser if you can. '
                    .'Send it together with `pin` and pin.prefer_selector=true. If you cannot inspect the page, '
                    .'omit it and send only a pin, and say in `body` which element you mean. '
                    .'Keep it under '.self::ELEMENT_MAX_LENGTH.' characters.'
                ),
        ];
    }

    public function handle(Request $mcp, HttpRequest $incoming): Response
    {
        $user = $this->caller($incoming);

        if ($user === null) {
            return $this->unauthenticatedResponse();
        }

        // Absent-but-null arguments are dropped, never sent as null: the
        // shared store rules run exactly like HTTP, where an unsent
        // priority/element/pin key is REMOVED by `sometimes`/`nullable` rather
        // than validated against null.
        $arguments = array_filter([
            'url' => $mcp->get('url'),
            'body' => $mcp->get('body'),
            'priority' => $mcp->get('priority'),
            'pin' => $mcp->get('pin'),
            'element' => $mcp->get('element'),
        ], static fn (mixed $value): bool => $value !== null);

        return $this->mapFailures(fn (): Response => Response::json(
            $this->created($user, $arguments)
        ));
    }

    /**
     * @param  array<string, mixed>  $arguments
     * @return array{id: string, url: string}
     */
    private function created(User $user, array $arguments): array
    {
        $this->validateMcpTightenings($arguments);

        $report = $this->actions->create($user, $this->withDerivedLocation($arguments));

        return [
            'id' => $report->id,
            'url' => (string) $report->url,
        ];
    }

    /**
     * The inputs tightened beyond the HTTP rule set (MCP contract): a pin
     * carries both coordinates, and `element` is capped at 512 characters.
     * Everything else validates on the shared store rules, which HTTP runs too -
     * the cap is MCP-only on purpose, because api.md documents `element` as a
     * bare optional string for the widget, which can generate long selectors.
     *
     * @param  array<string, mixed>  $arguments
     */
    private function validateMcpTightenings(array $arguments): void
    {
        Validator::make($arguments, [
            'pin' => ['nullable', 'array'],
            'pin.x' => ['required_with:pin', 'numeric', 'min:0', 'max:100'],
            'pin.y' => ['required_with:pin', 'numeric', 'min:0', 'max:100'],
            'element' => ['nullable', 'string', 'max:'.self::ELEMENT_MAX_LENGTH],
        ])->validate();
    }

    /**
     * The stored `location` is the page grouping key, never an agent-supplied
     * value (agents sent prose like `/ (hero description)` or unsorted keys,
     * which fragmented the location groups) - derive it exactly like the
     * widget would. An absent `url` stays with the shared store rules ("url
     * required"); a non-absolute `url` fails on `url` itself.
     *
     * @param  array<string, mixed>  $arguments
     * @return array<string, mixed>
     */
    private function withDerivedLocation(array $arguments): array
    {
        if (! array_key_exists('url', $arguments)) {
            return $arguments;
        }

        $location = PageLocation::fromUrl((string) $arguments['url']);

        if ($location === null) {
            throw ValidationException::withMessages([
                'url' => 'The url must be an absolute http(s) address, e.g. https://example.com/page.',
            ]);
        }

        return [...$arguments, 'location' => $location];
    }
}
