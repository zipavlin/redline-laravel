<?php

namespace Zipavlin\Redline\Providers\Storage;

use Illuminate\Http\UploadedFile;

/**
 * Bootstrap provider for Redline storage backends.
 *
 * Responsibilities:
 *  - register(): return model binding map (abstract → concrete)
 *  - apiUrl(): return the URL the widget should call
 *  - isLocal(): return false only for CloudStorageProvider
 *  - boot(): iterate register() and bind models in the IoC container
 *  - file system helpers shared by local providers
 */
abstract class StorageProvider
{
    /**
     * Return a map of abstract model class → concrete implementation.
     * The base boot() method iterates this and calls app()->bind() for each.
     *
     * @return array<class-string, class-string>
     */
    abstract public function register(): array;

    /**
     * The URL the widget uses for all API calls.
     * Local providers return the host app URL; CloudStorageProvider returns the remote URL.
     */
    abstract public function apiUrl(): string;

    /**
     * The URL the widget uses for dashboard access.
     * Local providers return the host app URL; CloudStorageProvider returns the remote URL.
     */
    abstract public function dashboardUrl(): string;

    /**
     * Whether local routes and storage are active.
     * Returns false only for CloudStorageProvider - skips route registration.
     */
    public function isLocal(): bool
    {
        return true;
    }

    /**
     * Bind all models returned by register() into the IoC container.
     * Called by RedlineServiceProvider during boot.
     */
    public function boot(): void
    {
        foreach ($this->register() as $abstract => $concrete) {
            app()->bind($abstract, $concrete);
        }
    }

    // -----------------------------------------------------------------------
    // File system helpers - available to local providers
    // -----------------------------------------------------------------------

    public function mediaPath(): string
    {
        return storage_path(implode(DIRECTORY_SEPARATOR, ['app', config('redline.storage.path.base')]));
    }

    public function screenshotPath(): string
    {
        return storage_path(implode(DIRECTORY_SEPARATOR, ['app', config('redline.storage.path.base'), config('redline.storage.path.screenshots')]));
    }

    public function videoPath(): string
    {
        return storage_path(implode(DIRECTORY_SEPARATOR, ['app', config('redline.storage.path.base'), config('redline.storage.path.videos')]));
    }

    public function databasePath(): string
    {
        return storage_path(implode(DIRECTORY_SEPARATOR, ['app', config('redline.storage.path.base'), config('redline.storage.path.database')]));
    }

    // -----------------------------------------------------------------------
    // File system handlers - available to local providers
    // -----------------------------------------------------------------------

    public function storeScreenshot(UploadedFile $file, string $id): string
    {
        $file->move($this->screenshotPath(), $id.'.png');

        return $id.'.png';
    }

    public function storeVideo(UploadedFile $file, string $id): string
    {
        $extension = match ($file->getMimeType()) {
            'video/mp4', 'video/quicktime', 'video/x-m4v' => 'mp4',
            default => 'webm',
        };

        $file->move($this->videoPath(), $id.'.'.$extension);

        return $id.'.'.$extension;
    }

    public function deleteScreenshot(?string $filename): void
    {
        $path = $this->screenshotPath().DIRECTORY_SEPARATOR.$filename;
        if ($filename && file_exists($path)) {
            unlink($path);
        }
    }

    public function deleteVideo(?string $filename): void
    {
        $path = $this->videoPath().DIRECTORY_SEPARATOR.$filename;
        if ($filename && file_exists($path)) {
            unlink($path);
        }
    }

    public function deleteMedia(): void
    {
        // Screenshots
        foreach (glob($this->screenshotPath().DIRECTORY_SEPARATOR.'*') ?: [] as $file) {
            if (is_file($file)) {
                unlink($file);
            }
        }

        // Videos
        foreach (glob($this->videoPath().DIRECTORY_SEPARATOR.'*') ?: [] as $file) {
            if (is_file($file)) {
                unlink($file);
            }
        }
    }

    protected function ensureDirectories(): void
    {
        foreach ([
            $this->mediaPath(),
            $this->screenshotPath(),
            $this->videoPath(),
        ] as $dir) {
            if (! is_dir($dir)) {
                mkdir($dir, 0755, true);
            }
        }
    }
}
