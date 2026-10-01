<?php

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Zipavlin\Redline\Enums\Role;
use Zipavlin\Redline\Models\Comment;
use Zipavlin\Redline\Models\Report;
use Zipavlin\Redline\Models\Screenshot;
use Zipavlin\Redline\Models\User;
use Zipavlin\Redline\Models\Video;
use Zipavlin\Redline\Providers\Storage\StorageProvider;

beforeEach(function () {
    ensureRedlineDirectories();
    // Purge to get a fresh :memory: connection with clean schema for each test.
    DB::purge('redline');
    (new StorageProvider)->boot();
});

// ---------------------------------------------------------------------------
// User
// ---------------------------------------------------------------------------

it('can create and find a user', function () {
    $user = User::on('redline')->create([
        'name' => 'Admin',
        'email' => 'admin@example.com',
        'role' => 'developer',
    ]);

    $found = User::on('redline')->find($user->id);

    expect($found->name)->toBe('Admin')
        ->and($found->role)->toBe(Role::DEVELOPER);
});

it('auto-generates a ULID on user creation', function () {
    $user = User::on('redline')->create([
        'name' => 'Bob', 'email' => 'bob@example.com', 'role' => 'reporter',
    ]);

    expect($user->id)->toMatch('/^[0-9a-f-]{36}$/');
});

// ---------------------------------------------------------------------------
// Report
// ---------------------------------------------------------------------------

it('can create and find a report', function () {
    $user = User::on('redline')->create(['name' => 'Alice', 'email' => 'alice@example.com', 'role' => 'reporter']);

    $report = Report::on('redline')->create([
        'author_id' => $user->id,
        'url' => 'https://example.com/page',
        'location' => '/page',
        'status' => 'new',
    ]);

    $found = Report::on('redline')->find($report->id);

    expect($found)->not->toBeNull()
        ->and($found->location)->toBe('/page')
        ->and($found->status)->toBe('new');
});

it('auto-generates a ULID on report creation', function () {
    $report = Report::on('redline')->create([
        'url' => 'https://example.com', 'location' => '/', 'status' => 'new',
    ]);

    expect($report->id)->toMatch('/^[0-9a-f-]{36}$/');
});

it('casts json columns on report', function () {
    $report = Report::on('redline')->create([
        'url' => 'https://example.com',
        'location' => '/',
        'status' => 'new',
        'pin' => ['x_percent' => 50.0, 'y_percent' => 50.0],
        'browser' => ['browser_name' => 'Chrome'],
    ]);

    $fresh = Report::on('redline')->find($report->id);

    expect($fresh->pin)->toBeArray()
        ->and((float) $fresh->pin['x_percent'])->toBe(50.0)
        ->and($fresh->browser['browser_name'])->toBe('Chrome');
});

it('report belongs to author', function () {
    $user = User::on('redline')->create(['name' => 'Jane', 'email' => 'jane@example.com', 'role' => 'reporter']);
    $report = Report::on('redline')->create([
        'author_id' => $user->id, 'url' => 'https://example.com', 'location' => '/', 'status' => 'new',
    ]);

    $loaded = Report::on('redline')->with('author')->find($report->id);

    expect($loaded->author)->not->toBeNull()
        ->and($loaded->author->name)->toBe('Jane');
});

// ---------------------------------------------------------------------------
// Screenshot
// ---------------------------------------------------------------------------

it('screenshot belongs to report', function () {
    $report = Report::on('redline')->create([
        'url' => 'https://example.com', 'location' => '/', 'status' => 'new',
    ]);

    Screenshot::on('redline')->create([
        'report_id' => $report->id,
        'path' => $report->id.'.png',
    ]);

    $loaded = Report::on('redline')->with('screenshot')->find($report->id);

    expect($loaded->screenshot)->not->toBeNull()
        ->and($loaded->screenshot->path)->toEndWith('.png');
});

// ---------------------------------------------------------------------------
// Video
// ---------------------------------------------------------------------------

it('video belongs to report', function () {
    $report = Report::on('redline')->create([
        'url' => 'https://example.com', 'location' => '/', 'status' => 'new',
    ]);

    Video::on('redline')->create([
        'report_id' => $report->id,
        'path' => $report->id.'.webm',
    ]);

    $loaded = Report::on('redline')->with('video')->find($report->id);

    expect($loaded->video)->not->toBeNull()
        ->and($loaded->video->path)->toEndWith('.webm');
});

// ---------------------------------------------------------------------------
// Comment
// ---------------------------------------------------------------------------

it('report has many comments ordered by created_at', function () {
    $user = User::on('redline')->create(['name' => 'Alice', 'email' => 'alice@example.com', 'role' => 'reporter']);
    $report = Report::on('redline')->create([
        'url' => 'https://example.com', 'location' => '/', 'status' => 'new',
    ]);

    Comment::on('redline')->create([
        'report_id' => $report->id, 'author_id' => $user->id, 'body' => 'First comment',
    ]);
    Comment::on('redline')->create([
        'report_id' => $report->id, 'author_id' => $user->id, 'body' => 'Second comment',
    ]);

    $loaded = Report::on('redline')->with('comments')->find($report->id);

    expect($loaded->comments)->toHaveCount(2)
        ->and($loaded->comments[0]->body)->toBe('First comment')
        ->and($loaded->comments[1]->body)->toBe('Second comment');
});

it('comment belongs to author', function () {
    $user = User::on('redline')->create(['name' => 'Bob', 'email' => 'bob@example.com', 'role' => 'developer']);
    $report = Report::on('redline')->create([
        'url' => 'https://example.com', 'location' => '/', 'status' => 'new',
    ]);
    $comment = Comment::on('redline')->create([
        'report_id' => $report->id, 'author_id' => $user->id, 'body' => 'A comment.',
    ]);

    $loaded = Comment::on('redline')->with('author')->find($comment->id);

    expect($loaded->author)->not->toBeNull()
        ->and($loaded->author->name)->toBe('Bob');
});

// ---------------------------------------------------------------------------
// Members (report <-> user pivot, no model of its own)
// ---------------------------------------------------------------------------

it('links reports and users through the member pivot', function () {
    $user = User::on('redline')->create(['name' => 'Member', 'email' => 'member@example.com', 'role' => 'developer']);
    $report = Report::on('redline')->create([
        'url' => 'https://example.com', 'location' => '/', 'status' => 'new',
    ]);

    $report->members()->attach($user->id);

    $loaded = Report::on('redline')->with('members')->find($report->id);

    expect($loaded->members)->toHaveCount(1)
        ->and($loaded->members->first()->id)->toBe($user->id)
        ->and($loaded->members->first()->name)->toBe('Member');
});

it('enforces one member row per report-user pair', function () {
    $user = User::on('redline')->create(['name' => 'Member', 'email' => 'unique-member@example.com', 'role' => 'developer']);
    $report = Report::on('redline')->create([
        'url' => 'https://example.com', 'location' => '/', 'status' => 'new',
    ]);

    DB::connection('redline')->table('redline_members')->insert([
        'report_id' => $report->id,
        'user_id' => $user->id,
    ]);

    expect(fn () => DB::connection('redline')->table('redline_members')->insert([
        'report_id' => $report->id,
        'user_id' => $user->id,
    ]))->toThrow(QueryException::class);
});
