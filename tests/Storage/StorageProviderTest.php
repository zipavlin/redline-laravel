<?php

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
