<?php

namespace Zipavlin\Redline\Commands;

use Illuminate\Console\Command;
use RuntimeException;
use Symfony\Component\Finder\Finder;
use Symfony\Component\Process\Process;
use Throwable;

class RemoveCommand extends Command
{
    protected $signature = 'redline:remove {--F|force : will skip confirmation}';

    protected $description = 'Remove Redline storage, env entries, config and package.';

    public function handle(): int
    {
        $this->line('');
        $this->line('  <fg=red>⚠</> <options=bold>IMPORTANT</> This will remove ALL Redline data.');
        $this->line('');

        $appName = $this->appName();

        if ($this->option('force') || $this->ask("Please write '$appName' to continue") === $appName) {
            $this->removeStorage();
            $this->removeAssets();
            $this->removeConfig();
            $this->removeEnvEntries();
            $this->scanForManualRemovals();

            if ($this->option('force') || $this->confirm('Do you want to remove the Redline package as well?')) {
                $this->removePackage();
            }

            $this->line('');
            $this->line('  <fg=green>✓</> Redline removed successfully.');
            $this->line('');
        }

        return self::SUCCESS;
    }

    /**
     * Derive the confirmation token from app.url (first URL label), falling
     * back to a safe static word when app.url is unset or unparseable so the
     * prompt always shows a meaningful value.
     */
    protected function appName(): string
    {
        $name = strtolower(trim((string) preg_replace(
            '/^(?:https?:\/\/)?([\w-]+)(?:\..+)?$/',
            '$1',
            (string) config('app.url', '')
        )));

        return $name === '' ? 'redline' : $name;
    }

    // -----------------------------------------------------------------------
    // Removal steps
    // -----------------------------------------------------------------------

    protected function removeStorage(): void
    {
        $this->info('Removing storage');

        if (config('redline.storage.provider') === CloudStorageProvider::class) {
            $this->line('  <fg=yellow>⚠</> Cloud storage - nothing local to delete.');
            $this->line('  Reports live on the remote Redline API - delete them there.');

            return;
        }

        $path = storage_path('app/'.config('redline.storage.path.base'));
        $resolved = realpath($path);

        if ($resolved === false) {
            if (is_dir($path)) {
                $this->error('Refusing to delete Redline storage - could not resolve '.$path);

                return;
            }

            $this->line('  <fg=yellow>⚠</> No Redline storage directory found at: '.$path);

            return;
        }

        $expectedBase = realpath(storage_path('app'));

        if ($expectedBase === false || ! str_starts_with($resolved, $expectedBase.DIRECTORY_SEPARATOR)) {
            $this->error('Refusing to delete Redline storage outside storage/app: '.$resolved);

            return;
        }

        $this->deleteDirectory($resolved);

        $this->line('  <fg=green>✓</> Redline storage removed ('.$resolved.')');
    }

    protected function removeAssets(): void
    {
        $this->info('Removing assets');

        $path = public_path('vendor/redline');

        if (! is_dir($path)) {
            $this->line('  <fg=yellow>⚠</> No published assets found at: '.$path);

            return;
        }

        $this->deleteDirectory($path);

        $this->line('  <fg=green>✓</> Published assets removed ('.$path.')');
    }

    protected function removeConfig(): void
    {
        $this->info('Removing config');

        $configPath = config_path('redline.php');

        if (file_exists($configPath)) {
            unlink($configPath);
            $this->line('  <fg=green>✓</> Published config removed ('.$configPath.')');
        } else {
            $this->line('  <fg=yellow>⚠</> No published config found at: '.$configPath);
        }

        $viewsPath = resource_path('views/vendor/redline');

        if (is_dir($viewsPath)) {
            $this->deleteDirectory($viewsPath);
            $this->line('  <fg=green>✓</> Published mail views removed ('.$viewsPath.')');
        } else {
            $this->line('  <fg=yellow>⚠</> No published mail views found at: '.$viewsPath);
        }
    }

    protected function removeEnvEntries(): void
    {
        $this->info('Removing env entries');

        $envPath = $this->envPath();

        if (! file_exists($envPath)) {
            $this->warn("  No .env file found at: $envPath - skipping.");

            return;
        }

        $content = (string) file_get_contents($envPath);
        $cleaned = $this->stripRedlineEnvLines($content);

        if ($cleaned === $content) {
            $this->line('  <fg=yellow>⚠</> No REDLINE_* entries found in: '.$envPath);

            return;
        }

        file_put_contents($envPath, $cleaned);

        $this->line('  <fg=green>✓</> REDLINE_* entries removed from: '.$envPath);
    }

    // -----------------------------------------------------------------------
    // Manual clean-up scan
    // -----------------------------------------------------------------------

    protected function scanForManualRemovals(): void
    {
        $this->info('Scanning for manual clean-ups');

        $needles = ['ShowRedlineWidget', 'Zipavlin\\Redline', 'RedlineServiceProvider'];

        $finder = Finder::create()
            ->files()
            ->in(base_path())
            ->name('*.php')
            ->exclude(['vendor', 'node_modules', 'storage'])
            ->notPath('bootstrap/cache')
            ->ignoreDotFiles(true)
            ->ignoreVCS(true)
            ->ignoreUnreadableDirs();

        $found = 0;

        foreach ($finder as $file) {
            $lines = file($file->getPathname(), FILE_IGNORE_NEW_LINES) ?: [];

            foreach ($lines as $number => $line) {
                foreach ($needles as $needle) {
                    if (! str_contains($line, $needle)) {
                        continue;
                    }

                    $this->line('  <fg=yellow>⚠</> '.str_replace('\\', '/', $file->getRelativePathname()).':'.($number + 1).' - contains '.$needle.', remove manually');
                    $found++;

                    break;
                }
            }
        }

        if ($found === 0) {
            $this->line('  <fg=green>✓</> No manual clean-ups required.');
        } else {
            $this->line('  <fg=yellow>⚠</> '.$found.' manual clean-up(s) found - remove them before finishing.');
        }
    }

    // -----------------------------------------------------------------------
    // Package removal
    // -----------------------------------------------------------------------

    protected function removePackage(): void
    {
        $this->info('Removing package');

        try {
            $process = $this->removePackageProcess();
            $process->setTimeout(300);
            $process->run(fn ($type, $buffer) => $this->output->write($buffer));

            if (! $process->isSuccessful()) {
                throw new RuntimeException('composer remove failed');
            }

            $this->line('  <fg=green>✓</> Redline package removed.');
        } catch (Throwable) {
            $this->line('  <fg=yellow>⚠</> Could not remove the Redline package automatically.');
            $this->line('  Run this manually: <options=bold>composer remove zipavlin/redline</>');
            $this->line('  Then delete any leftover Redline files and REDLINE_* entries by hand.');
        }
    }

    protected function removePackageProcess(): Process
    {
        return new Process(['composer', 'remove', 'zipavlin/redline'], base_path());
    }

    // -----------------------------------------------------------------------
    // Helpers
    // -----------------------------------------------------------------------

    protected function envPath(): string
    {
        return base_path('.env');
    }

    /**
     * Remove every line whose key starts with REDLINE_ (including the newline),
     * leaving all other lines byte-for-byte intact.
     */
    protected function stripRedlineEnvLines(string $content): string
    {
        return preg_replace('/^[ \t]*REDLINE_[A-Z0-9_]*(?:=.*)?\R?/m', '', $content) ?? $content;
    }

    protected function deleteDirectory(string $path): void
    {
        if (! is_dir($path)) {
            return;
        }

        foreach (scandir($path) ?: [] as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }

            $full = $path.DIRECTORY_SEPARATOR.$item;

            if (is_dir($full) && ! is_link($full)) {
                $this->deleteDirectory($full);
            } else {
                unlink($full);
            }
        }

        rmdir($path);
    }
}
