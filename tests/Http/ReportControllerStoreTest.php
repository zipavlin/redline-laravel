<?php

use Zipavlin\Redline\Models\User;

beforeEach(function () {
    ensureRedlineDirectories();
});

// ---------------------------------------------------------------------------
// Happy path
// ---------------------------------------------------------------------------

it('returns 201 with the created report on a valid request', function () {
    $this->postJson('/redline/api/report', makeReportData(), authHeaders('reporter'))
        ->assertStatus(201)
        ->assertJsonStructure([
            'id', 'created_at', 'status', 'author_id',
            'url', 'location', 'body', 'pin', 'browser',
        ]);
});

it('assigns id, created_at, and status=new on creation', function () {
    $body = $this->postJson('/redline/api/report', makeReportData(), authHeaders('reporter'))
        ->assertStatus(201)
        ->json();

    expect($body['id'])->toBeString()->not->toBeEmpty()
        ->and($body['created_at'])->toBeString()->not->toBeEmpty()
        ->and($body['status'])->toBe('new');
});

it('stores url and location as flat columns', function () {
    $data = makeReportData();

    $body = $this->postJson('/redline/api/report', $data, authHeaders('reporter'))
        ->assertStatus(201)
        ->json();

    expect($body['url'])->toBe($data['url'])
        ->and($body['location'])->toBe($data['location'])
        ->and($body['body'])->toBe($data['body']);
});

it('stores browser info', function () {
    $data = makeReportData();

    $body = $this->postJson('/redline/api/report', $data, authHeaders('reporter'))
        ->assertStatus(201)
        ->json();

    expect($body['browser']['browser_name'])->toBe('Chrome');
});

it('strips browser.user_agent from the created report payload', function () {
    $body = $this->postJson('/redline/api/report', makeReportData([
        'browser' => ['browser_name' => 'Chrome', 'user_agent' => 'secret-ua'],
    ]), authHeaders('reporter'))
        ->assertStatus(201)
        ->json();

    expect($body['browser']['browser_name'])->toBe('Chrome')
        ->and($body['browser'])->not->toHaveKey('user_agent');
});

// ---------------------------------------------------------------------------
// Guest mode
// ---------------------------------------------------------------------------

it('accepts unauthenticated submission when guest mode is enabled', function () {
    config(['redline.auth.guest' => true]);

    $this->postJson('/redline/api/report', makeReportData())
        ->assertStatus(201);
});

it('returns 401 for unauthenticated submission when guest mode is disabled', function () {
    config(['redline.auth.guest' => false]);

    $this->postJson('/redline/api/report', makeReportData())
        ->assertStatus(401);
});

// ---------------------------------------------------------------------------
// Validation
// ---------------------------------------------------------------------------

it('returns 422 when url is missing', function () {
    $data = makeReportData();
    unset($data['url']);

    $this->postJson('/redline/api/report', $data, authHeaders('reporter'))
        ->assertStatus(422)
        ->assertJsonValidationErrors(['url']);
});

it('returns 422 when location is missing', function () {
    $data = makeReportData();
    unset($data['location']);

    $this->postJson('/redline/api/report', $data, authHeaders('reporter'))
        ->assertStatus(422)
        ->assertJsonValidationErrors(['location']);
});

it('returns 422 when body is missing', function () {
    $data = makeReportData();
    unset($data['body']);

    $this->postJson('/redline/api/report', $data, authHeaders('reporter'))
        ->assertStatus(422)
        ->assertJsonValidationErrors(['body']);
});

// ---------------------------------------------------------------------------
// Auth
// ---------------------------------------------------------------------------

it('returns 401 when no token is provided', function () {
    $this->postJson('/redline/api/report', makeReportData())
        ->assertStatus(401);
});

// ---------------------------------------------------------------------------
// Forbidden callers - the centralized Actions exception rendering (review m1)
// ---------------------------------------------------------------------------

it('answers 403 for a forbidden caller instead of an uncaught 500', function () {
    config(['redline.auth.guest' => true]);

    // The shared guest identity resolves by email inside RedlineApiAuth, so a
    // row with that email but a pending, non-guest role passes the
    // middleware and reaches ReportActions::create - the one caller shape the
    // CREATE gate refuses. The renderer maps the ForbiddenAction to the same
    // 403 envelope the old per-controller catch produced.
    app(User::class)->create([
        'name' => 'Pending Guest Row',
        'email' => 'guest@redline.local',
        'role' => 'reporter',
        'status' => 'pending',
    ]);

    $this->postJson('/redline/api/report', makeReportData())
        ->assertStatus(403)
        ->assertJson(['message' => 'Forbidden.']);
});
