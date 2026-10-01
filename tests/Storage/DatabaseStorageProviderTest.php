<?php

use Illuminate\Support\Str;
use Zipavlin\Redline\Models\Base\Assignment as BaseAssignment;
use Zipavlin\Redline\Models\Base\Comment as BaseComment;
use Zipavlin\Redline\Models\Base\Report as BaseReport;
use Zipavlin\Redline\Models\Base\Screenshot as BaseScreenshot;
use Zipavlin\Redline\Models\Base\User as BaseUser;
use Zipavlin\Redline\Models\Base\Video as BaseVideo;
use Zipavlin\Redline\Models\Database\Assignment;
use Zipavlin\Redline\Models\Database\Comment;
use Zipavlin\Redline\Models\Database\Report;
use Zipavlin\Redline\Models\Database\Screenshot;
use Zipavlin\Redline\Models\Database\User;
use Zipavlin\Redline\Models\Database\Video;
use Zipavlin\Redline\Providers\Storage\DatabaseStorageProvider;

beforeEach(function () {
    ensureRedlineDirectories();
    $this->provider = new DatabaseStorageProvider;
});

// ---------------------------------------------------------------------------
// register()
// ---------------------------------------------------------------------------

it('register() returns a map of all six model bindings', function () {
    $bindings = $this->provider->register();

    expect($bindings)->toHaveCount(6)
        ->and($bindings[BaseUser::class])->toBe(User::class)
        ->and($bindings[BaseReport::class])->toBe(Report::class)
        ->and($bindings[BaseScreenshot::class])->toBe(Screenshot::class)
        ->and($bindings[BaseVideo::class])->toBe(Video::class)
        ->and($bindings[BaseComment::class])->toBe(Comment::class)
        ->and($bindings[BaseAssignment::class])->toBe(Assignment::class);
});

// ---------------------------------------------------------------------------
// isLocal() / apiUrl()
// ---------------------------------------------------------------------------

it('isLocal() returns true', function () {
    expect($this->provider->isLocal())->toBeTrue();
});

it('apiUrl() returns a URL ending with /redline/api', function () {
    expect($this->provider->apiUrl())->toEndWith('/redline/api');
});

// ---------------------------------------------------------------------------
// boot() - model bindings
// ---------------------------------------------------------------------------

it('after boot(), app(BaseUser::class) resolves to a User instance', function () {
    $this->provider->boot();

    expect(app(BaseUser::class))->toBeInstanceOf(User::class);
});

it('after boot(), app(BaseReport::class) resolves to a Report instance', function () {
    $this->provider->boot();

    expect(app(BaseReport::class))->toBeInstanceOf(Report::class);
});

it('after boot(), all six base types resolve to their Database implementations', function () {
    $this->provider->boot();

    expect(app(BaseUser::class))->toBeInstanceOf(User::class)
        ->and(app(BaseReport::class))->toBeInstanceOf(Report::class)
        ->and(app(BaseScreenshot::class))->toBeInstanceOf(Screenshot::class)
        ->and(app(BaseVideo::class))->toBeInstanceOf(Video::class)
        ->and(app(BaseComment::class))->toBeInstanceOf(Comment::class)
        ->and(app(BaseAssignment::class))->toBeInstanceOf(Assignment::class);
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
