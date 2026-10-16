<?php

namespace Zipavlin\Redline\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schema;
use Throwable;
use Zipavlin\Redline\Providers\Storage\StorageProvider;
use Zipavlin\Redline\RedlineServiceProvider;
use Zipavlin\Redline\Support\ComposerScripts;

class UpdateCommand extends Command
{
    protected $signature = 'redline:update
        {--F|force : Run without prompts (for deploy scripts)}
        {--dry-run : Show what would change, mutate nothing}
        {--no-backup : Skip the SQLite backup before migrating}';

    protected $description = 'Update Redline to the installed package version';

    protected $aliases = ['redline:upgrade'];

    public function handle(): int
    {
        $version = RedlineServiceProvider::getVersion();
        $this->printBanner($version);

        if (! $this->ensureInstalled()) {
            return self::FAILURE;
        }

        $pending = $this->pendingMigrations();
        $this->reportPending($pending);

        if ($this->option('dry-run')) {
            $this->reportDryRun($pending);

            return self::SUCCESS;
        }

        return $this->performUpgrade($version, $pending);
    }

    /**
     * @param  array<int, string>|null  $pending
     */
    private function performUpgrade(string $version, ?array $pending): int
    {
        if (! $this->backupDatabase()) {
            return self::FAILURE;
        }

        if (! $this->runMigrations($this->migrationPath())) {
            return self::FAILURE;
        }

        $this->publishAssets();
        $untouched = array_values(array_filter([
            $this->republishConfig(),
            $this->republishMailViews(),
        ]));
        $this->clearCaches();
        $this->handleComposerHook();
        $this->summarize($version, $pending, $untouched);

        return self::SUCCESS;
    }

    /**
     * Verify install already happened. An update never creates the database or
     * its directories - that is redline:install's job.
     */
    private function ensureInstalled(): bool
    {
        $this->configureDatabaseConnection();

        $database = (string) config('database.connections.redline.database');

        if ($database !== ':memory:' && ! file_exists($database)) {
            $this->reportNotInstalled("database not found at $database");

            return false;
        }

        if (! Schema::connection('redline')->hasTable('redline_reports')) {
            $this->reportNotInstalled('redline_reports table not found');

            return false;
        }

        return true;
    }

    /**
     * Wire up the `redline` connection when the console-side package boot
     * skipped it, without creating anything on disk.
     */
    private function configureDatabaseConnection(): void
    {
        if (config('database.connections.redline') !== null) {
            return;
        }

        config(['database.connections.redline' => [
            'driver' => 'sqlite',
            'database' => app(StorageProvider::class)->databasePath().DIRECTORY_SEPARATOR.'redline.db',
            'foreign_key_constraints' => true,
        ]]);
    }

    /**
     * The install hint is printed on its own line so a long database path can
     * never word-wrap the command name in two.
     */
    private function reportNotInstalled(string $detail): void
    {
        $this->error('  Redline is not installed - '.$detail.'.');
        $this->line('  Run `php artisan redline:install` first.');
    }

    /**
     * Migration names still to run against the `redline` connection, or null
     * when the migrator state cannot be read (the command then just attempts
     * a migrate rather than aborting the upgrade).
     *
     * @return array<int, string>|null
     */
    private function pendingMigrations(): ?array
    {
        try {
            $migrator = app('migrator');
            $migrator->setConnection('redline');

            $files = $migrator->getMigrationFiles([$this->migrationPath()]);

            if (! $migrator->repositoryExists()) {
                return array_keys($files);
            }

            return array_values(array_diff(array_keys($files), $migrator->getRepository()->getRan()));
        } catch (Throwable) {
            return null;
        }
    }

    private function migrationPath(): string
    {
        return realpath(__DIR__.'/../../database/redline') ?: __DIR__.'/../../database/redline';
    }

    /**
     * Path to the HOST composer.json. A seam for tests, which must never
     * rewrite the skeleton's own composer.json.
     */
    protected function composerJsonPath(): string
    {
        return base_path('composer.json');
    }

    private function printBanner(string $version): void
    {
        $this->line('');
        $this->line('  <fg=red>●</> <options=bold>Redline</> - update to '.$version);
        $this->line('');
    }

    /**
     * Current feature state for host awareness: nothing is enabled unless
     * REDLINE_FEATURES lists it, so an empty bundle must not read as a bug.
     */
    private function featuresState(): string
    {
        $enabled = RedlineServiceProvider::getFeatures();

        return $enabled === []
            ? 'none enabled - set REDLINE_FEATURES or run `php artisan redline:enable`'
            : implode(', ', $enabled).' enabled; every other feature is off';
    }

    /**
     * @param  array<int, string>|null  $pending
     */
    private function reportPending(?array $pending): void
    {
        if ($pending === null) {
            $this->line('  <fg=yellow>•</> Could not read migration state - will attempt to migrate.');

            return;
        }

        if ($pending === []) {
            $this->line('  <fg=green>✓</> Database is already up to date.');

            return;
        }

        $this->line('  Pending migrations:');

        foreach ($pending as $name) {
            $this->line("   - $name");
        }
    }

    /**
     * @param  array<int, string>|null  $pending
     */
    private function reportDryRun(?array $pending): void
    {
        $this->line('');
        $this->line('  <options=bold>Dry run</> - no changes made. Planned steps:');
        $this->line($pending === null
            ? '   - Run migrations (pending state unknown).'
            : '   - Run '.count($pending).' pending migration(s) against the redline database.');
        $this->line('   - Re-publish assets to '.public_path('vendor/redline').'.');
        $this->line('   - Features: '.$this->featuresState().'.');

        if (file_exists(config_path('redline.php'))) {
            $this->line('   - config/redline.php is published (would offer to re-publish).');
        }

        if (is_dir(resource_path('views/vendor/redline/mail'))) {
            $this->line('   - Published mail views detected (would offer to re-publish).');
        }

        if ((new ComposerScripts($this->composerJsonPath()))->hasUpdateHook()) {
            $this->line('   - The composer post-package-update hook is present.');
        } else {
            $this->line('   - The composer post-package-update hook is missing (would offer to add it).');
        }

        $this->line('');
    }

    /**
     * @param  array<int, string>|null  $pending
     * @param  array<int, string>  $untouched
     */
    private function summarize(string $version, ?array $pending, array $untouched): void
    {
        $this->line('');
        $this->line('  <fg=green>✓</> Redline updated to <options=bold>'.$version.'</>.');
        $this->line(match (true) {
            $pending === null => '  Migrations: attempted (state was unreadable).',
            $pending === [] => '  Migrations: none pending - already up to date.',
            default => '  Migrations: ran '.count($pending).' pending migration(s).',
        });
        $this->line('  Assets: re-published to '.public_path('vendor/redline').'.');
        $this->line('  Features: '.$this->featuresState().'.');

        if ($untouched !== []) {
            $this->line('  Left untouched: '.implode(' and ', $untouched).'.');
        }

        $this->line('');
    }

    private function backupDatabase(): bool
    {
        if ($this->option('no-backup')) {
            $this->line('  <fg=yellow>•</> --no-backup: skipped the database backup.');

            return true;
        }

        $database = (string) config('database.connections.redline.database');

        if ($database === ':memory:' || ! file_exists($database)) {
            return true;
        }

        $backup = $this->unusedBackupPath($database);

        if (! copy($database, $backup)) {
            $this->error("  Could not back up the database to $backup - aborting before migrating.");

            return false;
        }

        $this->line("  <fg=green>✓</> Database backed up to $backup");

        return true;
    }

    /**
     * Keep a second-resolution timestamp but never clobber an existing backup:
     * two runs inside the same second get distinct -1, -2, ... suffixes.
     */
    private function unusedBackupPath(string $database): string
    {
        $stamp = date('Ymd_His');
        $backup = "$database.$stamp.bak";

        for ($suffix = 1; file_exists($backup); $suffix++) {
            $backup = "$database.$stamp-$suffix.bak";
        }

        return $backup;
    }

    private function runMigrations(string $path): bool
    {
        $exitCode = Artisan::call('migrate', [
            '--path' => $path,
            '--database' => 'redline',
            '--realpath' => true,
            '--force' => true,
        ]);

        $this->output->write(Artisan::output());

        if ($exitCode !== 0) {
            $this->error('  Migration failed - assets and caches left untouched. Fix the error and re-run `php artisan redline:update`.');

            return false;
        }

        return true;
    }

    /**
     * Overwrite the published bundle. Install publishes without --force, so an
     * update must force here or the host keeps serving stale JS/CSS.
     */
    private function publishAssets(): void
    {
        Artisan::call('vendor:publish', [
            '--tag' => 'redline-assets',
            '--force' => true,
        ]);

        $this->line('  <fg=green>✓</> Assets re-published to '.public_path('vendor/redline').'.');
    }

    /**
     * Re-publish the optional config without clobbering host edits. Returns the
     * "published config" label only when a published copy existed and was left
     * in place, so the summary can name it; null when there was nothing to do.
     */
    private function republishConfig(): ?string
    {
        if (! file_exists(config_path('redline.php'))) {
            return null;
        }

        $this->warn('  Published config/redline.php may be missing new options.');

        if ($this->option('force')) {
            $this->line('  <fg=yellow>•</> --force: left it untouched - merge config/redline.php manually.');

            return 'published config';
        }

        if (! $this->confirm('Re-publish config/redline.php? This overwrites your edits.', false)) {
            return 'published config';
        }

        Artisan::call('vendor:publish', ['--tag' => 'redline-config', '--force' => true]);
        $this->line('  <fg=green>✓</> Config re-published.');

        return null;
    }

    /**
     * Mirror of republishConfig() for the mail templates: names the "mail views"
     * label only when a published copy was left in place.
     */
    private function republishMailViews(): ?string
    {
        if (! is_dir(resource_path('views/vendor/redline/mail'))) {
            return null;
        }

        $this->warn('  Published mail views may be missing new templates.');

        if ($this->option('force')) {
            $this->line('  <fg=yellow>•</> --force: left them untouched - compare with resources/views/mail manually.');

            return 'mail views';
        }

        if (! $this->confirm('Re-publish mail views? This overwrites your edits.', false)) {
            return 'mail views';
        }

        Artisan::call('vendor:publish', ['--tag' => 'redline-mail-views', '--force' => true]);
        $this->line('  <fg=green>✓</> Mail views re-published.');

        return null;
    }

    /**
     * Offer the composer `post-package-update` hook so future `composer update`
     * runs apply Redline updates automatically (see ComposerHook). Accepted by
     * default because it only affects later updates; never written under
     * --force, mirroring republishConfig()/republishMailViews().
     */
    private function handleComposerHook(): void
    {
        $scripts = new ComposerScripts($this->composerJsonPath());

        if ($scripts->hasUpdateHook()) {
            $this->line('  <fg=yellow>•</> Composer post-package-update hook already present.');

            return;
        }

        if ($this->option('force')) {
            $this->line('  <fg=yellow>•</> --force: skipped the composer post-package-update hook.');

            return;
        }

        if (! $this->confirm('Set up Redline to update automatically on package updates? (composer post-package-update hook)', true)) {
            $this->line('  <fg=yellow>•</> Skipped - re-run `php artisan redline:update` to add it.');

            return;
        }

        if ($scripts->addUpdateHook()) {
            $this->line('  <fg=green>✓</> Added the composer post-package-update hook.');

            return;
        }

        $this->warn('  <fg=yellow>⚠</> Could not write the composer post-package-update hook to composer.json.');
    }

    /**
     * Stale config/view/route caches can pin the previous package version after
     * a composer update, so clear them unconditionally.
     */
    private function clearCaches(): void
    {
        foreach (['config:clear', 'view:clear', 'route:clear'] as $command) {
            Artisan::call($command);
        }

        $this->line('  <fg=green>✓</> Cleared config, view and route caches.');
    }
}
