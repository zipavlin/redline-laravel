<?php

use Zipavlin\Redline\Models\Report;

/**
 * redline:location moves existing reports from one page location to another
 * with an exact match on `location`, never touching their url.
 */
it('renames matching rows and leaves other locations untouched', function () {
    app(Report::class)->create(makeReportData(['location' => '/old']));
    app(Report::class)->create(makeReportData(['location' => '/old']));
    app(Report::class)->create(makeReportData(['location' => '/keep']));

    $this->artisan('redline:location', ['from' => '/old', 'to' => '/new', '--force' => true])
        ->expectsOutputToContain('2 report(s)')
        ->assertExitCode(0);

    expect(Report::query()->where('location', '/new')->count())->toBe(2)
        ->and(Report::query()->where('location', '/keep')->count())->toBe(1)
        ->and(Report::query()->where('location', '/old')->count())->toBe(0);
});

it('exits 1 and changes nothing when no report matches', function () {
    app(Report::class)->create(makeReportData(['location' => '/other']));

    $this->artisan('redline:location', ['from' => '/old', 'to' => '/new', '--force' => true])
        ->expectsOutputToContain('Nothing is stored at /old')
        ->assertExitCode(1);

    expect(Report::query()->where('location', '/other')->count())->toBe(1)
        ->and(Report::query()->where('location', '/new')->count())->toBe(0);
});

it('reports the count and writes nothing on --dry-run', function () {
    app(Report::class)->create(makeReportData(['location' => '/old']));
    app(Report::class)->create(makeReportData(['location' => '/old']));

    $this->artisan('redline:location', ['from' => '/old', 'to' => '/new', '--dry-run' => true, '--force' => true])
        ->expectsOutputToContain('Dry run: 2 report(s)')
        ->assertExitCode(0);

    expect(Report::query()->where('location', '/old')->count())->toBe(2);
});

it('asks for confirmation without --force and moves on yes', function () {
    app(Report::class)->create(makeReportData(['location' => '/old']));

    $this->artisan('redline:location', ['from' => '/old', 'to' => '/new'])
        ->expectsConfirmation('Move 1 report(s) from /old to /new?', 'yes')
        ->assertExitCode(0);

    expect(Report::query()->where('location', '/new')->count())->toBe(1);
});

it('writes nothing when the confirmation is declined', function () {
    app(Report::class)->create(makeReportData(['location' => '/old']));

    $this->artisan('redline:location', ['from' => '/old', 'to' => '/new'])
        ->expectsConfirmation('Move 1 report(s) from /old to /new?', 'no')
        ->assertExitCode(0);

    expect(Report::query()->where('location', '/old')->count())->toBe(1);
});

it('finds nothing and exits 1 on a second run after renaming', function () {
    app(Report::class)->create(makeReportData(['location' => '/old']));

    $this->artisan('redline:location', ['from' => '/old', 'to' => '/new', '--force' => true])
        ->assertExitCode(0);

    $this->artisan('redline:location', ['from' => '/old', 'to' => '/new', '--force' => true])
        ->expectsOutputToContain('Nothing is stored at /old')
        ->assertExitCode(1);

    expect(Report::query()->where('location', '/new')->count())->toBe(1);
});

it('trims the current location and stores the new one as given', function () {
    app(Report::class)->create(makeReportData(['location' => '/old']));

    $this->artisan('redline:location', ['from' => '/old ', 'to' => '/new', '--force' => true])
        ->assertExitCode(0);

    expect(Report::query()->where('location', '/new')->value('location'))->toBe('/new')
        ->and(Report::query()->where('location', '/old')->count())->toBe(0);
});

it('rejects identical locations without writing', function () {
    app(Report::class)->create(makeReportData(['location' => '/old']));

    $this->artisan('redline:location', ['from' => '/old', 'to' => '/old', '--force' => true])
        ->expectsOutputToContain('must differ')
        ->assertExitCode(1);

    expect(Report::query()->where('location', '/old')->count())->toBe(1);
});

it('rejects blank locations without writing', function () {
    app(Report::class)->create(makeReportData(['location' => '/old']));

    $this->artisan('redline:location', ['from' => '   ', 'to' => '/new', '--force' => true])
        ->expectsOutputToContain('required')
        ->assertExitCode(1);

    expect(Report::query()->where('location', '/old')->count())->toBe(1);
});
