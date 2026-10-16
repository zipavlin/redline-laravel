<?php

namespace Zipavlin\Redline\Mcp\Tools;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Http\Request as HttpRequest;
use Illuminate\Validation\ValidationException;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;
use Laravel\Mcp\Support\ValidationMessages;
use Zipavlin\Redline\Actions\UserActions;
use Zipavlin\Redline\Exceptions\ForbiddenAction;
use Zipavlin\Redline\Mcp\Concerns\CanBuildQueryRequests;
use Zipavlin\Redline\Mcp\Concerns\HasCaller;
use Zipavlin\Redline\Models\User;

/**
 * `user_list` - the compact roster an agent needs to resolve people for
 * member assignment. Read-only, grammar-driven and role-gated through
 * `UserActions::list()` - the exact user list the HTTP endpoint serves, so
 * its visibility (email is PII, developer+ only) cannot drift from HTTP.
 */
#[IsReadOnly]
class UserListTool extends Tool
{
    use CanBuildQueryRequests;
    use HasCaller;

    public function __construct(private readonly UserActions $actions) {}

    protected string $name = 'user_list';

    protected string $title = 'List Users';

    public function description(): string
    {
        return <<<'TEXT'
            Paginated compact user list: id, name and role per row, for
            resolving who to assign as a report member. Email joins a row
            only for developer tokens (the same visibility the user list
            endpoint applies).
            TEXT;
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'per_page' => $schema->integer()
                ->min(1)
                ->max(self::MCP_MAX_PER_PAGE)
                ->description('Page size, capped at '.self::MCP_MAX_PER_PAGE.'.'),
            'page' => $schema->integer()->min(1)->description('1-based page number.'),
        ];
    }

    public function handle(Request $mcp, HttpRequest $incoming): Response
    {
        // The grammar reads the user through the synthetic request's resolver;
        // arriving here without one (the middleware chain guarantees it on the
        // wire) must fail loudly instead of silently matching zero rows.
        $user = $this->caller($incoming);

        if ($user === null) {
            return $this->unauthenticatedResponse();
        }

        try {
            // The shared grammar-driven listing HTTP GET /user runs, with the
            // caller's role gate - a caller UserActions rejects (a guest, for
            // instance) surfaces the forbidden error, never a 500.
            $listing = $this->actions->list($this->queryRequest($this->rowsQuery($user, $mcp->all()), $user));
        } catch (ForbiddenAction $exception) {
            return Response::error($exception->getMessage());
        } catch (ValidationException $exception) {
            return Response::error(ValidationMessages::from($exception));
        }

        return Response::json($listing);
    }

    /**
     * The compact projection: name and role for every caller; email joins
     * the includes only for developer tokens, exactly like the HTTP
     * include[] gate does (a denial there is an explicit 422, so a caller
     * without the gate is never sent the key in the first place).
     *
     * @return array<string, mixed>
     */
    private function rowsQuery(User $user, array $arguments): array
    {
        return [
            'include' => ['name', 'role', ...($user->isDeveloper ? ['email'] : [])],
            'paginate' => $this->paginateQuery($arguments),
        ];
    }
}
