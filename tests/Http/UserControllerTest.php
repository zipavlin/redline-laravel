<?php

use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Zipavlin\Redline\Mail\UserInvitationMail;
use Zipavlin\Redline\Models\Base\Comment;
use Zipavlin\Redline\Models\Base\Report;
use Zipavlin\Redline\Models\Base\User;

beforeEach(function () {
    ensureRedlineDirectories();
    $this->admin = makeUser('admin');
    $this->adminHeaders = ['Authorization' => 'Bearer '.makeAuthToken($this->admin)];
});

function makePendingUser(string $name = 'Pending Person'): User
{
    static $counter = 0;
    $counter++;

    return app(User::class)->create([
        'name' => $name,
        'email' => "pending{$counter}@example.com",
        'role' => 'reporter',
        'status' => 'pending',
    ]);
}

// ---------------------------------------------------------------------------
// Index
// ---------------------------------------------------------------------------

// The admin user table's row shape, expressed as include[] tokens (base row is
// id/status/created_at; everything else is include-gated, like every resource).
const USER_INCLUDE = 'include[]=name&include[]=email&include[]=role'
    .'&include[]=reports:count&include[]=comments:count';

it('returns paginated user list with counts for admins', function () {
    makeUser('reporter');
    makePendingUser();

    $body = $this->getJson('/redline/api/user?'.USER_INCLUDE, $this->adminHeaders)
        ->assertStatus(200)
        ->json();

    expect($body)->toHaveKeys(['data', 'meta'])
        ->and($body['data'][0])->toHaveKeys([
            'id', 'name', 'email', 'role', 'status', 'reports_count', 'comments_count',
        ]);

    // Tab counts live on /user/aggregate — the index no longer returns a counts bag.
    $counts = $this->getJson('/redline/api/user/aggregate?aggregate[status:count]=approved,pending', $this->adminHeaders)
        ->assertStatus(200)
        ->json();

    expect($counts['status']['count'])->toBe(['approved' => 2, 'pending' => 1]);
});

it('searches users by name and email via filter[search]', function () {
    app(User::class)->create(['name' => 'Zebra Zoe', 'email' => 'zoe@example.com', 'role' => 'reporter']);

    $body = $this->getJson('/redline/api/user?filter[search:like]=zebra&'.USER_INCLUDE, $this->adminHeaders)->json('data');

    expect($body)->toHaveCount(1)
        ->and($body[0]['name'])->toBe('Zebra Zoe');
});

it('filters users by role and sorts by name', function () {
    app(User::class)->create(['name' => 'Bravo Dev', 'email' => 'b@example.com', 'role' => 'developer']);
    app(User::class)->create(['name' => 'Alpha Dev', 'email' => 'a@example.com', 'role' => 'developer']);
    app(User::class)->create(['name' => 'Charlie Reporter', 'email' => 'c@example.com', 'role' => 'reporter']);

    $body = $this->getJson('/redline/api/user?filter[role]=developer&sort[name]=asc&'.USER_INCLUDE, $this->adminHeaders)
        ->assertStatus(200)
        ->json('data');

    expect(collect($body)->pluck('role')->unique()->all())->toBe(['developer'])
        ->and($body[0]['name'])->toBe('Alpha Dev')
        ->and($body[1]['name'])->toBe('Bravo Dev');
});

it('rejects non-admins from the user index', function () {
    $headers = authHeaders('developer');

    $this->getJson('/redline/api/user', $headers)->assertStatus(403);
});

// ---------------------------------------------------------------------------
// Show
// ---------------------------------------------------------------------------

it('returns user detail with stats', function () {
    $user = makeUser('reporter');

    $r1 = app(Report::class)->create([
        'author_id' => $user['id'], 'url' => 'https://x/a', 'location' => '/a',
        'body' => 'Open one', 'status' => 'new',
    ]);
    app(Report::class)->create([
        'author_id' => $user['id'], 'url' => 'https://x/b', 'location' => '/b',
        'body' => 'Resolved one', 'status' => 'resolved',
    ]);
    app(Comment::class)->create(['report_id' => $r1->id, 'author_id' => $user['id'], 'body' => 'c']);

    $body = $this->getJson("/redline/api/user/{$user['id']}?include[]=name&include[]=email&include[]=role&include[]=stats", $this->adminHeaders)
        ->assertStatus(200)
        ->json();

    expect($body['id'])->toBe($user['id'])
        ->and($body['stats']['reports'])->toBe(2)
        ->and($body['stats']['open'])->toBe(1)
        ->and($body['stats']['resolved'])->toBe(1);
});

it('rejects non-admins from user show', function () {
    $user = makeUser('reporter');

    $this->getJson("/redline/api/user/{$user['id']}", authHeaders('reporter'))->assertStatus(403);
});

// ---------------------------------------------------------------------------
// Aggregate (replaces the deleted /user/overview)
// ---------------------------------------------------------------------------

it('returns user status counts via /user/aggregate for admins', function () {
    makeUser('reporter');
    makePendingUser('Waiting User');

    $counts = $this->getJson('/redline/api/user/aggregate?aggregate[status:count]=approved,pending', $this->adminHeaders)
        ->assertStatus(200)
        ->json();

    expect($counts['status']['count'])->toBe(['approved' => 2, 'pending' => 1]);

    // Role-filtered variant — mirrors the frontend's useUserTabCounts contract.
    $reporterCounts = $this->getJson('/redline/api/user/aggregate?aggregate[status:count]=approved,pending&filter[role]=reporter', $this->adminHeaders)
        ->assertStatus(200)
        ->json();

    expect($reporterCounts['status']['count'])->toBe(['approved' => 1, 'pending' => 1]);
});

// ---------------------------------------------------------------------------
// Store / Update / Destroy
// ---------------------------------------------------------------------------

it('creates a user via store and returns the index-row shape', function () {
    $body = $this->postJson('/redline/api/user', [
        'name' => 'New Person',
        'email' => 'new@example.com',
        'role' => 'developer',
        'password' => 'secret123',
    ], $this->adminHeaders)
        ->assertStatus(201)
        ->json();

    expect($body)->toHaveKeys(['id', 'name', 'email', 'role', 'status', 'reports_count', 'comments_count'])
        ->and($body['status'])->toBe('approved')
        ->and($body['role'])->toBe('developer');

    expect(app(User::class)->where('email', 'new@example.com')->exists())->toBeTrue();
});

it('creates a user without a password and emails a set-password invitation when mail is configured', function () {
    config(['mail.default' => 'smtp']);
    Mail::fake();

    $body = $this->postJson('/redline/api/user', [
        'name' => 'Invited Person',
        'email' => 'invited@example.com',
        'role' => 'reporter',
    ], $this->adminHeaders)
        ->assertStatus(201)
        ->json();

    expect($body)->toHaveKey('invitation_sent', true)
        ->and(app(User::class)->where('email', 'invited@example.com')->first()->password)->toBeNull();

    Mail::assertSent(UserInvitationMail::class);
});

it('requires a password on store when no mailer is configured', function () {
    config(['mail.default' => 'array']);

    $this->postJson('/redline/api/user', [
        'name' => 'No Mail Person',
        'email' => 'nomail@example.com',
        'role' => 'reporter',
    ], $this->adminHeaders)->assertStatus(422);
});

it('rejects a duplicate email on store', function () {
    app(User::class)->create(['name' => 'Dup', 'email' => 'dup@example.com', 'role' => 'reporter']);

    $this->postJson('/redline/api/user', [
        'name' => 'Dup 2', 'email' => 'dup@example.com', 'role' => 'reporter', 'password' => 'secret123',
    ], $this->adminHeaders)->assertStatus(422);
});

it('rejects non-admins from store', function () {
    $this->postJson('/redline/api/user', [
        'name' => 'X', 'email' => 'x@example.com', 'role' => 'reporter', 'password' => 'secret123',
    ], authHeaders('developer'))->assertStatus(403);
});

it('updates a user via update', function () {
    $user = makeUser('reporter');

    $body = $this->patchJson("/redline/api/user/{$user['id']}", [
        'role' => 'developer',
        'status' => 'pending',
    ], $this->adminHeaders)
        ->assertStatus(200)
        ->json();

    expect($body['role'])->toBe('developer')
        ->and($body['status'])->toBe('pending');
});

it('rejects non-admins from update', function () {
    $user = makeUser('reporter');

    $this->patchJson("/redline/api/user/{$user['id']}", ['role' => 'admin'], authHeaders('developer'))
        ->assertStatus(403);
});

it('deletes a user via destroy', function () {
    $user = makeUser('reporter');

    $this->deleteJson("/redline/api/user/{$user['id']}", [], $this->adminHeaders)
        ->assertStatus(204);

    expect(app(User::class)->find($user['id']))->toBeNull();
});

it('rejects non-admins from destroy', function () {
    $user = makeUser('reporter');

    $this->deleteJson("/redline/api/user/{$user['id']}", [], authHeaders('reporter'))
        ->assertStatus(403);
});

// ---------------------------------------------------------------------------
// Approve / Reset password
// ---------------------------------------------------------------------------

it('approves a pending user', function () {
    $pending = makePendingUser();

    $body = $this->postJson("/redline/api/user/{$pending->id}/approve", [], $this->adminHeaders)
        ->assertStatus(200)
        ->json();

    expect($body['status'])->toBe('approved')
        ->and(app(User::class)->find($pending->id)->status)->toBe('approved');
});

it('rejects non-admins from approve', function () {
    $pending = makePendingUser();

    $this->postJson("/redline/api/user/{$pending->id}/approve", [], authHeaders('developer'))
        ->assertStatus(403);
});

it('resets a user password', function () {
    $user = makeUser('reporter');

    $this->postJson("/redline/api/user/{$user['id']}/reset-password", [
        'password' => 'brandnew123',
    ], $this->adminHeaders)
        ->assertStatus(200)
        ->assertExactJson(['ok' => true]);

    $fresh = app(User::class)->find($user['id']);
    expect(Hash::check('brandnew123', $fresh->password))->toBeTrue();
});

it('validates password length on reset-password', function () {
    $user = makeUser('reporter');

    $this->postJson("/redline/api/user/{$user['id']}/reset-password", [
        'password' => 'short',
    ], $this->adminHeaders)->assertStatus(422);
});

it('rejects non-admins from reset-password', function () {
    $user = makeUser('reporter');

    $this->postJson("/redline/api/user/{$user['id']}/reset-password", [
        'password' => 'brandnew123',
    ], authHeaders('reporter'))->assertStatus(403);
});
