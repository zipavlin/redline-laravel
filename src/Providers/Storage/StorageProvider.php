<?php

namespace Zipavlin\Redline\Providers\Storage;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schema;
use Zipavlin\Redline\Models\Base\Assignment;
use Zipavlin\Redline\Models\Base\Comment;
use Zipavlin\Redline\Models\Base\Report;
use Zipavlin\Redline\Models\Base\Screenshot;
use Zipavlin\Redline\Models\Base\User;
use Zipavlin\Redline\Models\Base\Video;
use Zipavlin\Redline\Models\Database\Assignment as DatabaseAssignment;
use Zipavlin\Redline\Models\Database\Comment as DatabaseComment;
use Zipavlin\Redline\Models\Database\Report as DatabaseReport;
use Zipavlin\Redline\Models\Database\Screenshot as DatabaseScreenshot;
use Zipavlin\Redline\Models\Database\User as DatabaseUser;
use Zipavlin\Redline\Models\Database\Video as DatabaseVideo;

/**
 * The package's single local storage backend: Eloquent models on a dedicated
 * SQLite connection, plus uploaded media under `storage/app/{path.base}`.
 *
 * Responsibilities:
 *  - register(): return the model binding map (abstract model → Database implementation)
 *  - apiUrl(): return the URL the widget calls for the API
 *  - dashboardUrl(): return the URL the dashboard is served from
 *  - boot(): ensure directories, configure the `redline` connection, ensure the
 *    schema exists, then bind the model implementations in the IoC container
 *  - media helpers shared by the package controllers
 */
class StorageProvider
{
    /**
     * Return a map of abstract model class → concrete implementation.
     * boot() iterates this and calls app()->bind() for each entry.
     *
     * @return array<class-string, class-string>
     */
    public function register(): array
    {
        return [
            User::class => DatabaseUser::class,
            Report::class => DatabaseReport::class,
            Screenshot::class => DatabaseScreenshot::class,
            Video::class => DatabaseVideo::class,
            Comment::class => DatabaseComment::class,
            Assignment::class => DatabaseAssignment::class,
        ];
    }

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
     * Prepare the local storage backend and bind its models.
     * Called by RedlineServiceProvider during boot.
     */
    public function boot(): void
    {
        $this->ensureDirectories();
        $this->configureConnection();
        $this->ensureSchema();

        foreach ($this->register() as $abstract => $concrete) {
            app()->bind($abstract, $concrete);
        }
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
                || ! Schema::connection('redline')->hasTable('redline_assignments')) {
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

        // An install predating 0006 has reports but not assignments; surface the
        // upgrade instead of letting every assignment query fail at runtime.
        if (! Schema::connection('redline')->hasTable('redline_assignments')) {
            throw new \RuntimeException(
                'Redline database is missing the redline_assignments table. Run `php artisan redline:install` to migrate it.'
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
