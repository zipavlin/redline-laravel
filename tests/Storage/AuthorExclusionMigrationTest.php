<?php

use Illuminate\Support\Facades\DB;
use Zipavlin\Redline\Models\Report;
use Zipavlin\Redline\Models\User;

beforeEach(function () {
    ensureRedlineDirectories();
});

/**
 * The package migrations already ran against the empty in-memory database at
 * boot, so the historical-row cleanup in 0008 is exercised here by seeding a
 * pre-0008 author-member pair and re-running the migration's up().
 */
it('removes historical author-member rows without touching other members', function () {
    $author = app(User::class)->create([
        'name' => 'Author',
        'email' => 'author@example.com',
        'role' => 'reporter',
    ]);
    $other = app(User::class)->create([
        'name' => 'Other',
        'email' => 'other@example.com',
        'role' => 'developer',
    ]);

    $report = app(Report::class)->create([
        'author_id' => $author->id,
        'url' => 'https://staging.example.com/dashboard',
        'location' => '/dashboard',
        'body' => 'Bug report.',
        'status' => 'new',
    ]);

    DB::connection('redline')->table('redline_members')->insert([
        ['report_id' => $report->id, 'user_id' => $author->id],
        ['report_id' => $report->id, 'user_id' => $other->id],
    ]);

    $migration = require dirname(__DIR__, 2).'/database/redline/0008_add_author_exclusion_to_redline_members_table.php';
    $migration->up();

    $remaining = DB::connection('redline')->table('redline_members')
        ->where('report_id', $report->id)
        ->pluck('user_id')
        ->all();

    expect($remaining)->toBe([$other->id]);
});
