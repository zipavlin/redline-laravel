<?php

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Zipavlin\Redline\Providers\Storage\StorageProvider;

beforeEach(function () {
    ensureRedlineDirectories();
    $this->provider = new StorageProvider;
});

// ---------------------------------------------------------------------------
// apiUrl()
// ---------------------------------------------------------------------------

it('apiUrl() returns a URL ending with /redline/api', function () {
    expect($this->provider->apiUrl())->toEndWith('/redline/api');
});

// ---------------------------------------------------------------------------
// boot() - missing database file
// ---------------------------------------------------------------------------

it('boot() throws a friendly RuntimeException when the database file is missing', function () {
    config(['database.connections.redline' => [
        'driver' => 'sqlite',
        'database' => storage_path('app/redline/database/does-not-exist-'.Str::uuid().'.db'),
        'foreign_key_constraints' => true,
    ]]);

    expect(fn () => $this->provider->boot())
        ->toThrow(RuntimeException::class, 'Run `php artisan redline:install`');
});

// ---------------------------------------------------------------------------
// boot() - migration drift
// ---------------------------------------------------------------------------

it('boot() does not check for per-migration drift', function () {
    $database = sys_get_temp_dir().DIRECTORY_SEPARATOR.'redline-storage-'.uniqid().'.db';
    touch($database);

    config(['database.connections.redline.database' => $database]);
    DB::purge('redline');

    Artisan::call('migrate', [
        '--path' => dirname(__DIR__, 2).'/database/redline',
        '--database' => 'redline',
        '--realpath' => true,
        '--force' => true,
    ]);

    // Simulate an install predating 0006 and 0009: boot must still succeed,
    // because keeping an installed schema current is redline:update's job
    // (wired as the composer post-package-update listener), not boot's.
    Schema::connection('redline')->drop('redline_members');
    Schema::connection('redline')->table('redline_reports', function ($table): void {
        $table->dropIndex(['due_at']);
        $table->dropColumn('due_at');
    });

    expect(fn () => $this->provider->boot())->not->toThrow(Throwable::class);

    foreach (glob($database.'*') ?: [] as $file) {
        @unlink($file);
    }
});
