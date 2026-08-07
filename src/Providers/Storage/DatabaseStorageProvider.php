<?php

namespace Zipavlin\Redline\Providers\Storage;

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schema;
use Zipavlin\Redline\Contracts\Listable;
use Zipavlin\Redline\Models\Base\Comment;
use Zipavlin\Redline\Models\Base\Report;
use Zipavlin\Redline\Models\Base\Screenshot;
use Zipavlin\Redline\Models\Base\User;
use Zipavlin\Redline\Models\Base\Video;
use Zipavlin\Redline\Models\Database\Comment as DatabaseComment;
use Zipavlin\Redline\Models\Database\Report as DatabaseReport;
use Zipavlin\Redline\Models\Database\Screenshot as DatabaseScreenshot;
use Zipavlin\Redline\Models\Database\User as DatabaseUser;
use Zipavlin\Redline\Models\Database\Video as DatabaseVideo;

/**
 * Eloquent + SQLite storage backend.
 *
 * Binds all six model contracts to their Database (Eloquent) implementations,
 * configures the dedicated 'redline' SQLite connection, and ensures the schema exists.
 */
class DatabaseStorageProvider extends StorageProvider implements Listable
{
    public static function title(): string
    {
        return 'Database (SQLite - recommended)';
    }

    public static function position(): int
    {
        return 1;
    }

    public function register(): array
    {
        return [
            User::class => DatabaseUser::class,
            Report::class => DatabaseReport::class,
            Screenshot::class => DatabaseScreenshot::class,
            Video::class => DatabaseVideo::class,
            Comment::class => DatabaseComment::class,
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
        $this->ensureDirectories();
        $this->configureConnection();
        $this->ensureSchema();

        parent::boot();
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
            if (! Schema::connection('redline')->hasTable('redline_reports')) {
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
}
