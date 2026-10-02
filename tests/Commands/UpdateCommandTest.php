<?php

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * redline:update upgrades an already-installed Redline: it runs only the package
 * migrations still pending, re-publishes the built assets, and handles the
 * optional config/mail re-publishes without clobbering host edits.
 */
afterEach(function () {
    $assets = public_path('vendor/redline');

    if (is_dir($assets)) {
        app('files')->deleteDirectory($assets);
    }

    if (file_exists(config_path('redline.php'))) {
        app('files')->delete(config_path('redline.php'));
    }

    $mailViews = resource_path('views/vendor/redline');

    if (is_dir($mailViews)) {
        app('files')->deleteDirectory($mailViews);
    }
});

it('is registered, reports the version and re-publishes assets', function () {
    // The testbench build has no released version, so getVersion() mints a
    // fresh UUID per call and cannot be compared literally; assert the banner
    // shape instead (the line always carries the version).
    $this->artisan('redline:update --force')
        ->expectsOutputToContain('Redline - update to')
        ->expectsOutputToContain('already up to date')
        ->assertExitCode(0);

    expect(public_path('vendor/redline/redline.js'))->toBeFile();
});

it('is also available under the redline:upgrade alias', function () {
    $this->artisan('redline:upgrade --force')
        ->expectsOutputToContain('Redline updated to')
        ->assertExitCode(0);
});

it('detects and runs a migration the redline database has not applied', function () {
    Schema::connection('redline')->drop('redline_members');
    DB::connection('redline')->table('migrations')
        ->where('migration', '0006_create_redline_members_table')
        ->delete();

    $this->artisan('redline:update --force')
        ->expectsOutputToContain('0006_create_redline_members_table')
        ->assertExitCode(0);

    expect(Schema::connection('redline')->hasTable('redline_members'))->toBeTrue();
});

it('mutates nothing in dry-run mode', function () {
    $this->artisan('redline:update --dry-run')
        ->expectsOutputToContain('Dry run')
        ->assertExitCode(0);

    expect(is_dir(public_path('vendor/redline')))->toBeFalse();
});

it('fails the preflight with an install hint when redline is not installed', function () {
    Schema::connection('redline')->drop('redline_reports');

    $this->artisan('redline:update --force')
        ->expectsOutputToContain('Redline is not installed')
        ->expectsOutputToContain('redline:install')
        ->assertExitCode(1);
});

it('backs up a file-based redline database before migrating', function () {
    $database = sys_get_temp_dir().DIRECTORY_SEPARATOR.'redline-update-'.uniqid().'.db';
    touch($database);

    config(['database.connections.redline.database' => $database]);
    DB::purge('redline');

    Artisan::call('migrate', [
        '--path' => dirname(__DIR__, 2).'/database/redline',
        '--database' => 'redline',
        '--realpath' => true,
        '--force' => true,
    ]);

    $this->artisan('redline:update --force')->assertExitCode(0);

    expect(glob($database.'.*.bak'))->not->toBeEmpty();

    foreach (glob($database.'*') ?: [] as $file) {
        @unlink($file);
    }
});

it('never overwrites a published config under --force', function () {
    $path = config_path('redline.php');
    app('files')->put($path, "<?php\n\n// host-customised\nreturn [];\n");

    $this->artisan('redline:update --force')->assertExitCode(0);

    expect(file_get_contents($path))->toContain('host-customised');
});

it('leaves a published config alone when the re-publish prompt is declined', function () {
    $path = config_path('redline.php');
    app('files')->put($path, "<?php\n\n// host-customised\nreturn [];\n");

    $this->artisan('redline:update')
        ->expectsConfirmation('Re-publish config/redline.php? This overwrites your edits.', 'no')
        ->assertExitCode(0);

    expect(file_get_contents($path))->toContain('host-customised');
});

it('overwrites a published config when the re-publish prompt is answered yes', function () {
    $path = config_path('redline.php');
    app('files')->put($path, "<?php\n\n// host-customised\nreturn [];\n");

    $this->artisan('redline:update')
        ->expectsConfirmation('Re-publish config/redline.php? This overwrites your edits.', 'yes')
        ->assertExitCode(0);

    expect(file_get_contents($path))->not->toContain('host-customised');
});

it('never overwrites published mail views under --force', function () {
    $path = resource_path('views/vendor/redline/mail/new-report.blade.php');
    app('files')->ensureDirectoryExists(dirname($path));
    app('files')->put($path, 'host-customised-mail-view');

    $this->artisan('redline:update --force')->assertExitCode(0);

    expect(file_get_contents($path))->toBe('host-customised-mail-view');
});

it('overwrites a published mail view when the re-publish prompt is answered yes', function () {
    $path = resource_path('views/vendor/redline/mail/new-report.blade.php');
    app('files')->ensureDirectoryExists(dirname($path));
    app('files')->put($path, 'host-customised-mail-view');

    $this->artisan('redline:update')
        ->expectsConfirmation('Re-publish mail views? This overwrites your edits.', 'yes')
        ->assertExitCode(0);

    $source = dirname(__DIR__, 2).'/resources/views/mail/new-report.blade.php';

    expect(file_get_contents($path))->toBe(file_get_contents($source));
});

it('keeps two backups made within the same second from colliding', function () {
    $database = sys_get_temp_dir().DIRECTORY_SEPARATOR.'redline-update-'.uniqid().'.db';
    touch($database);

    config(['database.connections.redline.database' => $database]);
    DB::purge('redline');

    Artisan::call('migrate', [
        '--path' => dirname(__DIR__, 2).'/database/redline',
        '--database' => 'redline',
        '--realpath' => true,
        '--force' => true,
    ]);

    $this->artisan('redline:update --force')->assertExitCode(0);
    $this->artisan('redline:update --force')->assertExitCode(0);

    expect(glob($database.'.*.bak'))->toHaveCount(2);

    foreach (glob($database.'*') ?: [] as $file) {
        @unlink($file);
    }
});
