<?php

use Firebase\JWT\JWT;
use Illuminate\Http\Request as HttpRequest;
use Illuminate\Support\Str;
use Laravel\Mcp\Request as McpRequest;
use Laravel\Mcp\Response as McpResponse;
use Symfony\Component\Mailer\Envelope;
use Symfony\Component\Mailer\Exception\TransportException;
use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mailer\Transport\TransportInterface;
use Symfony\Component\Mime\RawMessage;
use Zipavlin\Redline\Models\Report;
use Zipavlin\Redline\Models\Token;
use Zipavlin\Redline\Models\User;
use Zipavlin\Redline\Tests\TestCase;

uses(TestCase::class)->in(__DIR__);

// ---------------------------------------------------------------------------
// Shared test helpers - available in every test file
// ---------------------------------------------------------------------------

/**
 * Minimal valid report payload for the new flat API format.
 */
function makeReportData(array $overrides = []): array
{
    return array_merge([
        'url' => 'https://staging.example.com/dashboard',
        'location' => '/dashboard',
        'body' => 'Something is broken here.',
        'pin' => ['x' => 34.5, 'y' => 62.1, 'scroll_x' => 0, 'scroll_y' => 0],
        'browser' => [
            'browser_name' => 'Chrome',
            'browser_version' => '121.0',
            'os' => 'macOS',
            'viewport_width' => 1440,
            'viewport_height' => 900,
        ],
    ], $overrides);
}

/**
 * Create a user directly in the DB (bypassing auth provider).
 * Returns the user as an array.
 */
function makeUser(string $role = 'reporter'): array
{
    static $counter = 0;
    $counter++;

    return app(User::class)->create([
        'name' => "Test User {$counter}",
        'email' => "user{$counter}@example.com",
        'role' => $role,
    ])->toArray();
}

/**
 * Issue a JWT for the given user array (must have id, role, name, email).
 */
function makeAuthToken(array $user): string
{
    $key = config('app.key', '');

    if (str_starts_with($key, 'base64:')) {
        $key = base64_decode(substr($key, 7));
    }

    $now = time();

    return JWT::encode([
        'sub' => $user['id'],
        'role' => $user['role'],
        'name' => $user['name'],
        'email' => $user['email'],
        'iat' => $now,
        'exp' => $now + 3600,
    ], $key, 'HS256');
}

/**
 * Returns Authorization headers for a user with the given role.
 */
function authHeaders(string $role = 'developer'): array
{
    $user = makeUser($role);

    return ['Authorization' => 'Bearer '.makeAuthToken($user)];
}

/**
 * Create an approved user plus an `rlm_` MCP API token for it.
 *
 * Returns ["user" => User, "token" => plaintext-token]; the plaintext is what
 * a request would carry in the Authorization header.
 */
function makeApiToken(string $role = 'developer', array $overrides = []): array
{
    static $counter = 0;
    $counter++;

    $user = app(User::class)->create(array_merge([
        'name' => "Token User {$counter}",
        'email' => "token-user{$counter}@example.com",
        'role' => $role,
    ], $overrides));

    $token = tokenFor($user->refresh(), 'test token');

    return ['user' => $user, 'token' => $token];
}

/**
 * Mint an `rlm_` token for an existing user (the same format the API-token
 * path lookup accepts).
 */
function tokenFor(User $user, string $name = 'test token'): string
{
    return Token::issue($user->refresh(), $name);
}

/**
 * A bare approved user usable as an API-token owner.
 */
function makeTokenOwner(): User
{
    static $counter = 0;
    $counter++;

    return app(User::class)->create([
        'name' => "Token Owner {$counter}",
        'email' => "token-owner{$counter}@example.com",
        'role' => 'developer',
    ]);
}

/**
 * A minimal valid JSON-RPC initialize request (legacy shape, no MCP headers -
 * enough to prove the route reached the MCP server machinery).
 */
function initializeMcpBody(): array
{
    return [
        'jsonrpc' => '2.0',
        'id' => 1,
        'method' => 'initialize',
        'params' => ['protocolVersion' => '2025-06-18'],
    ];
}

/**
 * Seed a real screenshot file (binary endpoint / size-gate tests) and return
 * only its file name.
 */
function newScreenshotFilePath(): string
{
    ensureRedlineDirectories();
    $file = 'shot-'.Str::uuid().'.png';
    file_put_contents(storage_path('app/redline/screenshots').DIRECTORY_SEPARATOR.$file, 'binary-bytes');

    return $file;
}

/**
 * An approved Redline user in the given role, usable as the caller behind a
 * synthetic MCP tool request (the counter keeps emails and names unique per
 * test run without depending on the DB increment behavior).
 */
function makeMcpCallerUser(string $role = 'developer'): User
{
    static $counter = 0;
    $counter++;

    return app(User::class)->create([
        'name' => "MCP Caller {$counter}",
        'email' => "mcp-caller{$counter}@example.com",
        'role' => $role,
    ]);
}

/**
 * A report created through the standard payload, attributed to `$author`
 * when given (attribute_id stays NULL otherwise - same shape as a guest
 * submission).
 */
function createMcpReport(?User $author = null, array $overrides = []): Report
{
    $payload = $author instanceof User
        ? ['author_id' => $author->id, ...makeReportData($overrides)]
        : makeReportData($overrides);

    return app(Report::class)->create($payload);
}

/**
 * Run an MCP tool's public handle() the way the MCP invoker does: a
 * Laravel\Mcp\Request carrying the tool arguments, and an incoming HTTP
 * request whose user resolver is the authenticated caller ($user = null
 * covers the no-auth path the wire can never reach).
 */
function mcpToolRun(string $toolClass, array $arguments = [], ?User $user = null): McpResponse
{
    $incoming = HttpRequest::create('/', 'POST', $arguments);
    $incoming->setUserResolver(static fn (?string $guard = null) => $user);

    return app($toolClass)->handle(new McpRequest($arguments), $incoming);
}

/**
 * The tool response's text content - the JSON payload for a result, or the
 * human-readable message for an error.
 */
function mcpToolText(McpResponse $response): string
{
    return (string) $response->content();
}

/**
 * Decode a JSON tool result payload (Response::json ships the listing as
 * text content).
 */
function mcpToolPayload(McpResponse $response): array
{
    return json_decode(mcpToolText($response), true, 512, JSON_THROW_ON_ERROR);
}

/**
 * One POST of tools/list against the real MCP endpoint, with the current
 * config state live - proving the merge is read at serve time, not baked
 * into boot.
 *
 * @return array<int, string>
 */
function mcpToolsListed(): array
{
    $token = makeApiToken();

    $response = test()->postJson('/redline/api/mcp', [
        'jsonrpc' => '2.0',
        'id' => 1,
        'method' => 'tools/list',
        'params' => [],
    ], ['Authorization' => 'Bearer '.$token['token']]);

    expect($response->status())->toBe(200);

    return array_map(
        fn (array $entry) => $entry['name'],
        (array) ($response->json('result.tools') ?? []),
    );
}

/**
 * Delete all report and user records (schema is fresh per test, but kept for clarity).
 */
function cleanDatabaseStorage(): void
{
    try {
        app(Report::class)->query()->delete();
        app(User::class)->query()->delete();
    } catch (Throwable) {
        // Swallow - storage may not be initialized yet in some test contexts.
    }
}

/**
 * Ensure the storage directory tree exists for tests.
 */
function ensureRedlineDirectories(): void
{
    $base = storage_path('app/redline');

    foreach ([$base, $base.'/screenshots', $base.'/videos'] as $dir) {
        if (! is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
    }
}

/**
 * Write a throwaway composer.json under storage/app and return its path, so
 * command tests never rewrite the skeleton's own composer.json.
 */
function composerJsonFixture(string $contents = '{}'): string
{
    $path = storage_path('app/composer-json-'.uniqid().'.json');
    file_put_contents($path, $contents);

    return $path;
}

/**
 * Delete every composer.json fixture a test left behind.
 */
function cleanComposerJsonFixtures(): void
{
    foreach (glob(storage_path('app/composer-json-*')) ?: [] as $file) {
        @unlink($file);
    }
}

/**
 * Swap the default mailer's transport for one that always throws, so mail
 * flows can be exercised through their failure path without a real SMTP
 * connection.
 */
function failingMailer(): void
{
    app('mail.manager')->mailer()->setSymfonyTransport(new class implements TransportInterface
    {
        public function send(RawMessage $message, ?Envelope $envelope = null): ?SentMessage
        {
            throw new TransportException('Simulated transport failure.');
        }

        public function __toString(): string
        {
            return 'failing';
        }
    });
}
