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
// boot() - missing priority column
// ---------------------------------------------------------------------------

it('boot() throws a friendly RuntimeException when the priority column is missing', function () {
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

    Schema::connection('redline')->table('redline_reports', function ($table): void {
        $table->dropIndex(['priority']);
        $table->dropColumn('priority');
    });

    expect(fn () => $this->provider->boot())
        ->toThrow(RuntimeException::class, 'missing the priority column');

    foreach (glob($database.'*') ?: [] as $file) {
        @unlink($file);
    }
});
