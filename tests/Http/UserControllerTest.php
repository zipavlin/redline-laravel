<?php

use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Zipavlin\Redline\Mail\RegistrationApprovedMail;
use Zipavlin\Redline\Mail\UserInvitationMail;
use Zipavlin\Redline\Models\Comment;
use Zipavlin\Redline\Models\Report;
use Zipavlin\Redline\Models\User;

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

    // Tab counts live on /user/aggregate - the index no longer returns a counts bag.
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

it('lets a reporter search by name but never by email fragment', function () {
    app(User::class)->create(['name' => 'Zebra Zoe', 'email' => 'zoe@example.com', 'role' => 'reporter']);

    $byName = $this->getJson('/redline/api/user?filter[search:like]=zebra&include[]=name', authHeaders('reporter'))
        ->assertStatus(200)
        ->json('data');

    expect($byName)->toHaveCount(1)
        ->and($byName[0]['name'])->toBe('Zebra Zoe');

    // The same reporter must not be able to walk the email column one fragment at a
    // time through the member-picker search.
    $byEmail = $this->getJson('/redline/api/user?filter[search:like]=zoe@example&include[]=name', authHeaders('reporter'))
        ->assertStatus(200)
        ->json('data');

    expect($byEmail)->toBe([]);
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

it('lets reporters and developers list users, with filters and includes', function () {
    makeUser('reporter');
    makePendingUser();

    // No `email` here: only developers/admins may read it, and a reporter asking
    // for it is a 422 (see below).
    $include = 'include[]=name&include[]=role&include[]=reports:count&include[]=comments:count';

    foreach (['reporter', 'developer'] as $role) {
        $body = $this->getJson('/redline/api/user?'.$include.'&filter[status]=approved', authHeaders($role))
            ->assertStatus(200)
            ->json();

        expect($body)->toHaveKeys(['data', 'meta'])
            ->and($body['data'][0])->toHaveKeys([
                'id', 'status', 'created_at', 'name', 'role', 'reports_count', 'comments_count',
            ])
            ->and($body['data'][0])->not->toHaveKey('email')
            ->and(collect($body['data'])->pluck('status')->unique()->all())->toBe(['approved']);
    }
});

it('sorts the user list by report and comment counts', function () {
    $threeReports = makeUser('reporter');
    $threeComments = makeUser('reporter');
    $oneComment = makeUser('reporter');

    $reports = collect(range(1, 3))->map(fn (int $i) => app(Report::class)->create([
        'author_id' => $threeReports['id'], 'url' => "https://x/{$i}", 'location' => "/r{$i}",
        'body' => 'r', 'status' => 'new',
    ]));

    // One report of their own for the commenter, plus three comments on the first
    // user's reports and one from the third user - so reports_count and
    // comments_count each form a distinct, total order across the three.
    app(Report::class)->create([
        'author_id' => $threeComments['id'], 'url' => 'https://x/own', 'location' => '/own',
        'body' => 'r', 'status' => 'new',
    ]);

    foreach ($reports as $report) {
        app(Comment::class)->create(['report_id' => $report->id, 'author_id' => $threeComments['id'], 'body' => 'c']);
    }
    app(Comment::class)->create(['report_id' => $reports->first()->id, 'author_id' => $oneComment['id'], 'body' => 'c']);

    $byReports = $this->getJson('/redline/api/user?filter[role]=reporter&include[]=name&sort[reports_count]=desc', $this->adminHeaders)
        ->assertStatus(200)
        ->json('data');

    expect(collect($byReports)->pluck('name')->all())
        ->toBe([$threeReports['name'], $threeComments['name'], $oneComment['name']]);

    $byComments = $this->getJson('/redline/api/user?filter[role]=reporter&include[]=name&sort[comments_count]=asc', $this->adminHeaders)
        ->assertStatus(200)
        ->json('data');

    expect(collect($byComments)->pluck('name')->all())
        ->toBe([$threeReports['name'], $oneComment['name'], $threeComments['name']]);
});

it('rejects a reporter asking for the email include with 422', function () {
    $this->getJson('/redline/api/user?include[]=name&include[]=email', authHeaders('reporter'))
        ->assertStatus(422)
        ->assertJsonValidationErrors(['include.email']);
});

it('rejects a reporter filtering by email with 422', function () {
    $this->getJson('/redline/api/user?filter[email:like]=example.com', authHeaders('reporter'))
        ->assertStatus(422)
        ->assertJsonValidationErrors(['filter.email:like']);
});

it('rejects a pending caller from the user index with 401', function () {
    $pending = makePendingUser();

    $this->getJson('/redline/api/user', ['Authorization' => 'Bearer '.makeAuthToken($pending->toArray())])
        ->assertStatus(401);
});

it('rejects unauthenticated callers from the user index', function () {
    $this->getJson('/redline/api/user')->assertStatus(401);
});

it('serves the widget member-picker query to a reporter', function () {
    $caller = makeUser('reporter');
    $developer = makeUser('developer');
    $admin = makeUser('admin');
    makePendingUser();

    $body = $this->getJson(
        '/redline/api/user?filter[status]=approved&filter[role:in]=reporter,developer,admin'
            .'&include[]=name&include[]=initials&sort[name]=asc',
        ['Authorization' => 'Bearer '.makeAuthToken($caller)],
    )
        ->assertStatus(200)
        ->json('data');

    // Four approved users (the beforeEach admin plus the three seeded here); the
    // pending user is dropped by filter[status].
    $expectedNames = [$this->admin['name'], $caller['name'], $developer['name'], $admin['name']];
    sort($expectedNames);

    expect($body)->toHaveCount(4)
        ->and($body[0])->toHaveKeys(['id', 'status', 'created_at', 'name', 'initials'])
        ->and($body[0])->not->toHaveKey('email')
        // Report-specific base fields (priority) must not leak onto other resources.
        ->and($body[0])->not->toHaveKey('priority')
        ->and(collect($body)->pluck('status')->unique()->all())->toBe(['approved'])
        ->and(collect($body)->pluck('name')->all())->toBe($expectedNames);
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

    // Role-filtered variant - mirrors the frontend's useUserTabCounts contract.
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
        ->and($body['invitation_error'])->toBeNull()
        ->and(app(User::class)->where('email', 'invited@example.com')->first()->password)->toBeNull();

    Mail::assertSent(UserInvitationMail::class);
});

it('reports a failed invitation send without failing user creation', function () {
    config(['mail.default' => 'smtp']);
    failingMailer();

    $body = $this->postJson('/redline/api/user', [
        'name' => 'Failed Invite',
        'email' => 'failed@example.com',
        'role' => 'reporter',
    ], $this->adminHeaders)
        ->assertStatus(201)
        ->json();

    expect($body['invitation_sent'])->toBeFalse()
        ->and($body['invitation_error'])->toBe('send_failed')
        ->and(app(User::class)->where('email', 'failed@example.com')->exists())->toBeTrue();
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

it('rejects a case-variant duplicate email on store', function () {
    app(User::class)->create(['name' => 'Dup', 'email' => 'case-dup@example.com', 'role' => 'reporter']);

    $this->postJson('/redline/api/user', [
        'name' => 'Dup 2', 'email' => 'Case-Dup@Example.com', 'role' => 'reporter', 'password' => 'secret123',
    ], $this->adminHeaders)->assertStatus(422);
});

it('stores a new user email lowercased', function () {
    $body = $this->postJson('/redline/api/user', [
        'name' => 'Mixed Case', 'email' => 'Mixed@Example.COM', 'role' => 'reporter', 'password' => 'secret123',
    ], $this->adminHeaders)
        ->assertStatus(201)
        ->json();

    expect($body['email'])->toBe('mixed@example.com')
        ->and(app(User::class)->where('email', 'mixed@example.com')->exists())->toBeTrue();
});

it('rejects a duplicate email on update', function () {
    $user = makeUser('reporter');
    $other = makeUser('reporter');

    $this->patchJson("/redline/api/user/{$user['id']}", [
        'email' => $other['email'],
    ], $this->adminHeaders)->assertStatus(422);
});

it('rejects a case-variant duplicate email on update', function () {
    $user = makeUser('reporter');
    $other = makeUser('reporter');

    $this->patchJson("/redline/api/user/{$user['id']}", [
        'email' => mb_strtoupper($other['email']),
    ], $this->adminHeaders)->assertStatus(422);
});

it('allows keeping the same email in a different case on update', function () {
    $user = makeUser('reporter');

    $body = $this->patchJson("/redline/api/user/{$user['id']}", [
        'email' => mb_strtoupper($user['email']),
    ], $this->adminHeaders)
        ->assertStatus(200)
        ->json();

    expect($body['email'])->toBe($user['email']);
});

it('allows keeping the same email on update', function () {
    $user = makeUser('reporter');

    $this->patchJson("/redline/api/user/{$user['id']}", [
        'email' => $user['email'],
    ], $this->adminHeaders)->assertStatus(200);
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

it('reports the correct report and comment counts in mutation responses', function () {
    $user = makeUser('reporter');
    $report = app(Report::class)->create([
        'author_id' => $user['id'], 'url' => 'https://x/one', 'location' => '/one',
        'body' => 'r', 'status' => 'new',
    ]);
    app(Comment::class)->create(['report_id' => $report->id, 'author_id' => $user['id'], 'body' => 'c']);

    $updated = $this->patchJson("/redline/api/user/{$user['id']}", ['role' => 'developer'], $this->adminHeaders)
        ->assertStatus(200)
        ->json();

    $approved = $this->postJson("/redline/api/user/{$user['id']}/approve", [], $this->adminHeaders)
        ->assertStatus(200)
        ->json();

    expect($updated['reports_count'])->toBe(1)
        ->and($updated['comments_count'])->toBe(1)
        ->and($approved['reports_count'])->toBe(1)
        ->and($approved['comments_count'])->toBe(1);
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

// ---------------------------------------------------------------------------
// Approval notification mail
// ---------------------------------------------------------------------------

it('sends a registration-approved mail when approving a pending user', function () {
    config(['mail.default' => 'smtp']);
    Mail::fake();
    $pending = makePendingUser();

    $this->postJson("/redline/api/user/{$pending->id}/approve", [], $this->adminHeaders)->assertStatus(200);

    Mail::assertSent(RegistrationApprovedMail::class, function (RegistrationApprovedMail $mail) use ($pending) {
        $html = $mail->render();

        return $mail->hasTo($pending->email)
            && str_contains($html, $mail->dashboardUrl)
            && str_contains($html, $pending->name);
    });
});

it('does not resend the approval mail on repeated approve', function () {
    config(['mail.default' => 'smtp']);
    Mail::fake();
    $pending = makePendingUser();

    $this->postJson("/redline/api/user/{$pending->id}/approve", [], $this->adminHeaders)->assertStatus(200);
    $this->postJson("/redline/api/user/{$pending->id}/approve", [], $this->adminHeaders)->assertStatus(200);

    Mail::assertSent(RegistrationApprovedMail::class, 1);
});

it('approval succeeds even when the approval mail transport fails', function () {
    config(['mail.default' => 'smtp']);
    failingMailer();
    $pending = makePendingUser();

    $body = $this->postJson("/redline/api/user/{$pending->id}/approve", [], $this->adminHeaders)
        ->assertStatus(200)
        ->json();

    expect($body['status'])->toBe('approved')
        ->and(app(User::class)->find($pending->id)->status)->toBe('approved');
});

it('does not send the approval mail when no mailer is configured', function () {
    config(['mail.default' => 'array']);
    Mail::fake();
    $pending = makePendingUser();

    $this->postJson("/redline/api/user/{$pending->id}/approve", [], $this->adminHeaders)->assertStatus(200);

    Mail::assertNothingSent();
});

it('sends the approval mail when update approves a pending user', function () {
    config(['mail.default' => 'smtp']);
    Mail::fake();
    $pending = makePendingUser();

    $this->patchJson("/redline/api/user/{$pending->id}", ['status' => 'approved'], $this->adminHeaders)->assertStatus(200);

    Mail::assertSent(RegistrationApprovedMail::class, 1);
});

it('does not send the approval mail when update keeps an approved user approved', function () {
    config(['mail.default' => 'smtp']);
    Mail::fake();
    $user = makeUser('reporter');

    $this->patchJson("/redline/api/user/{$user['id']}", ['status' => 'approved'], $this->adminHeaders)->assertStatus(200);

    Mail::assertNothingSent();
});

it('does not send the approval mail when update moves a user to pending', function () {
    config(['mail.default' => 'smtp']);
    Mail::fake();
    $pending = makePendingUser();
    $approved = makeUser('reporter');

    $this->patchJson("/redline/api/user/{$pending->id}", ['status' => 'pending'], $this->adminHeaders)->assertStatus(200);
    $this->patchJson("/redline/api/user/{$approved['id']}", ['status' => 'pending'], $this->adminHeaders)->assertStatus(200);

    Mail::assertNothingSent();
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
