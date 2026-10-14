<?php

use Firebase\JWT\JWT;
use Symfony\Component\Mailer\Envelope;
use Symfony\Component\Mailer\Exception\TransportException;
use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mailer\Transport\TransportInterface;
use Symfony\Component\Mime\RawMessage;
use Zipavlin\Redline\Models\Report;
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
