<?php

namespace Zipavlin\Redline\Providers\Storage;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schema;

/**
 * The package's single local storage backend: Eloquent models on a dedicated
 * SQLite connection, plus uploaded media under `storage/app/{path.base}`.
 *
 * Responsibilities:
 *  - apiUrl(): return the URL the widget calls for the API
 *  - dashboardUrl(): return the URL the dashboard is served from
 *  - boot(): ensure directories, configure the `redline` connection, ensure the
 *    schema exists
 *  - media helpers shared by the package controllers
 */
class StorageProvider
{
    /**
     * The URL the widget uses for all API calls.
     */
    public function apiUrl(): string
    {
        return url('/redline/api');
    }

    /**
     * The URL the widget uses for dashboard access.
     */
    public function dashboardUrl(): string
    {
        return url('/redline');
    }

    /**
     * Prepare the local storage backend.
     * Called by RedlineServiceProvider during boot.
     */
    public function boot(): void
    {
        $this->ensureDirectories();
        $this->configureConnection();
        $this->ensureSchema();
    }

    // -----------------------------------------------------------------------
    // Private setup helpers
    // -----------------------------------------------------------------------

    private function configureConnection(): void
    {
        // Don't overwrite if already configured - lets tests inject ':memory:'.
        if (config('database.connections.redline') !== null) {
            return;
        }

        config(['database.connections.redline' => [
            'driver' => 'sqlite',
            'database' => $this->databasePath().DIRECTORY_SEPARATOR.'redline.db',
            'foreign_key_constraints' => true,
        ]]);
    }

    /**
     * Create schema automatically for in-memory databases (testing).
     * For file-based databases, tables must exist (created by redline:install).
     */
    private function ensureSchema(): void
    {
        $db = config('database.connections.redline.database', '');

        if ($db === ':memory:') {
            if (! Schema::connection('redline')->hasTable('redline_reports')
                || ! Schema::connection('redline')->hasTable('redline_members')) {
                $this->runMigrations();
            }

            return;
        }

        // The SQLite connector throws a cryptic "Database file does not exist"
        // QueryException when the file is missing; fail with the install hint
        // instead. redline:install creates the file - never this boot.
        if (! file_exists($db)) {
            throw new \RuntimeException(
                'Redline database not found at ['.$db.']. Run `php artisan redline:install` to set up the database.'
            );
        }

        if (! Schema::connection('redline')->hasTable('redline_reports')) {
            throw new \RuntimeException(
                'Redline database tables not found. Run `php artisan redline:install` to set up the database.'
            );
        }

        // An install predating 0006 has reports but not members; surface the
        // upgrade instead of letting every membership query fail at runtime.
        if (! Schema::connection('redline')->hasTable('redline_members')) {
            throw new \RuntimeException(
                'Redline database is missing the redline_members table. Run `php artisan redline:install` to migrate it.'
            );
        }

        // An install predating 0007 has reports and members but not the priority
        // column; surface the upgrade instead of letting priority reads fail at runtime.
        if (! Schema::connection('redline')->hasColumn('redline_reports', 'priority')) {
            throw new \RuntimeException(
                'Redline database is missing the priority column. Run `php artisan redline:update` to migrate it.'
            );
        }

        // Same upgrade guard for 0009's due_at - it is a serialized base row field,
        // so a missing column would otherwise break every report read, not just ones
        // that filter on deadlines.
        if (! Schema::connection('redline')->hasColumn('redline_reports', 'due_at')) {
            throw new \RuntimeException(
                'Redline database is missing the due_at column. Run `php artisan redline:update` to migrate it.'
            );
        }
    }

    /**
     * Run the package migration files against the redline connection.
     * Used only for in-memory (test) databases - production uses artisan migrate.
     */
    private function runMigrations(): void
    {
        Artisan::call('migrate', [
            '--path' => realpath(__DIR__.'/../../../database/redline'),
            '--database' => 'redline',
            '--realpath' => true,
        ]);
    }

    // -----------------------------------------------------------------------
    // File system helpers
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
    // File system handlers
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
