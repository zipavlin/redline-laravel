<?php

namespace Zipavlin\Redline\Providers\Storage;

use Zipavlin\Redline\Contracts\Listable;
use Zipavlin\Redline\Models\Base\Comment;
use Zipavlin\Redline\Models\Base\Report;
use Zipavlin\Redline\Models\Base\Screenshot;
use Zipavlin\Redline\Models\Base\User;
use Zipavlin\Redline\Models\Base\Video;
use Zipavlin\Redline\Models\File\Comment as FileComment;
use Zipavlin\Redline\Models\File\Report as FileReport;
use Zipavlin\Redline\Models\File\Screenshot as FileScreenshot;
use Zipavlin\Redline\Models\File\User as FileUser;
use Zipavlin\Redline\Models\File\Video as FileVideo;
use Zipavlin\Redline\RedlineServiceProvider;

/**
 * JSON flatfile storage backend (requires ryangjchandler/orbit, Laravel 12+).
 *
 * Binds all six model contracts to their Flatfile (Orbit) implementations.
 * Orbit models are only available when the package is installed.
 */
class FileStorageProvider extends StorageProvider implements Listable
{
    public static function title(): string
    {
        return 'File (JSON files via ryangjchandler/orbit)';
    }

    public static function position(): int
    {
        return 2;
    }

    public function register(): array
    {
        return [
            User::class => FileUser::class,
            Report::class => FileReport::class,
            Screenshot::class => FileScreenshot::class,
            Video::class => FileVideo::class,
            Comment::class => FileComment::class,
        ];
    }

    public function apiUrl(): string
    {
        return url('/redline/api');
    }

    public function dashboardUrl(): string
    {
        return url('/redline');
    }

    public function boot(): void
    {
        // Fail fast with a clear message when File storage is picked without orbit:
        // the Orbit model classes are guarded by a top-level `return`, so without
        // this check boot() would silently bind classes that do not exist.
        if (! RedlineServiceProvider::orbitConfigured()) {
            throw new \RuntimeException(
                'FileStorageProvider requires ryangjchandler/orbit. Run: composer require ryangjchandler/orbit'
            );
        }

        $this->ensureDirectories();

        parent::boot();
    }
}
