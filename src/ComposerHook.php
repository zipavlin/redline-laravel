<?php

namespace Zipavlin\Redline;

use Symfony\Component\Process\Process;
use Throwable;
use Zipavlin\Redline\Support\UpdateMarker;

/**
 * Composer script listener that keeps an installed Redline current.
 *
 * `redline:install` (and `redline:update` for pre-existing installs) migrate
 * the host app's composer.json to the per-run `post-autoload-dump` entry
 * `Zipavlin\Redline\ComposerHook::ensureCurrent`, so a composer run echoes
 * the hook once (instead of once per updated package) and re-runs
 * `redline:update --force` only when the installed version has drifted from
 * the marker file (see UpdateMarker). The legacy per-package
 * `postPackageUpdate` entry stays supported for hosts still carrying it
 * until their first migrated run.
 *
 * `composer/composer` is not a dependency of this package, so the event is
 * duck-typed rather than type-hinted: the class has to load and stay silent
 * even where Composer's own classes are unavailable.
 */
final class ComposerHook
{
    public const PACKAGE = 'zipavlin/redline';

    public const SKIP_ENV = 'REDLINE_SKIP_UPDATE_HOOK';

    private const TIMEOUT = 300;

    /**
     * The `post-autoload-dump` script entry: keep the install current after
     * every composer run. Fires once per run, so composer's output shows the
     * hook once; the actual update only spawns on version drift.
     *
     * Never throws and never fails the parent composer command: under
     * Composer 2 a script exiting non-zero fails the whole command, so any
     * failure is swallowed into a printed failure report and a void return.
     */
    public static function ensureCurrent(): void
    {
        if (self::skipped()) {
            return;
        }

        try {
            $root = (string) getcwd();

            if (! is_file($root.DIRECTORY_SEPARATOR.'artisan')
                || UpdateMarker::isCurrent($root, UpdateMarker::currentVersionKey())) {
                return;
            }

            self::runUpdate($root);
        } catch (Throwable $e) {
            self::reportFailure($e->getMessage());
        }
    }

    /**
     * Composer script entry point for the legacy per-package hook. `$event` is
     * Composer's post-package-update event (Composer\Installer\PackageEvent),
     * duck-typed because composer/composer is not a dependency of this
     * package.
     *
     * Never throws and never fails the parent `composer update`: that
     * operation already succeeded, and a failed migration must not turn it
     * into a failure.
     */
    public static function postPackageUpdate(object $event): void
    {
        if (! self::shouldUpdate($event)) {
            return;
        }

        $root = (string) getcwd();

        if (! is_file($root.DIRECTORY_SEPARATOR.'artisan')) {
            self::write('  • [redline] No artisan file found - skipped the automatic redline:update.');

            return;
        }

        self::runUpdate($root);
    }

    /**
     * Whether this post-package-update event concerns Redline itself and the
     * skip switch is off.
     *
     * @param  object  $event  Composer's post-package-update event (Composer\Installer\PackageEvent).
     */
    public static function shouldUpdate(object $event): bool
    {
        return ! self::skipped() && self::updatedPackageName($event) === self::PACKAGE;
    }

    public static function skipped(): bool
    {
        return filter_var(getenv(self::SKIP_ENV), FILTER_VALIDATE_BOOLEAN);
    }

    /**
     * The updated package's name, read off the operation the event carries.
     *
     * Composer's post-package-update event is Composer\Installer\PackageEvent,
     * dispatched by EventDispatcher::dispatchPackageEvent() from
     * InstallationManager. It exposes getOperation() - not the updated package
     * directly - and the operation for an update is an UpdateOperation whose
     * getTargetPackage() is the incoming package. Every link stays duck-typed
     * because composer/composer is not a dependency here and the real event
     * class must remain unloadable.
     *
     * @param  object  $event  Composer's post-package-update event (Composer\Installer\PackageEvent).
     */
    public static function updatedPackageName(object $event): ?string
    {
        if (! method_exists($event, 'getOperation')) {
            return null;
        }

        $operation = $event->getOperation();

        if ($operation === null || ! method_exists($operation, 'getTargetPackage')) {
            return null;
        }

        $package = $operation->getTargetPackage();

        return $package !== null && method_exists($package, 'getName') ? $package->getName() : null;
    }

    /**
     * Spawn `redline:update --force` and stream its STDOUT/STDERR live through
     * the hook's output streams, so migrations, asset publishing and cache
     * clears show progress while running. Shared by both entry points. The
     * spawn is best-effort: any throwable becomes a printed failure report,
     * never a script exit code.
     */
    private static function runUpdate(string $root): void
    {
        self::write('  [redline] running php artisan redline:update --force...');

        try {
            $process = self::newProcess($root);
            $process->run(fn (string $type, string $data) => self::streamChunk($type, $data));
        } catch (Throwable $e) {
            self::reportFailure($e->getMessage());

            return;
        }

        if ($process->isSuccessful()) {
            self::write('  [redline] redline:update finished successfully.');

            return;
        }

        self::reportFailure(
            'exit '.$process->getExitCode(),
            $process->getOutput().$process->getErrorOutput(),
        );
    }

    private static function reportFailure(string $detail, string $output = ''): void
    {
        self::writeError('[redline] Automatic "php artisan redline:update --force" failed ('.$detail.'). Run it manually.');

        foreach (array_slice(self::outputLines($output), -20) as $line) {
            self::writeError('  '.$line);
        }
    }

    /**
     * The spawned update process, or whatever the test factory seam
     * substitutes for one in the suite.
     */
    private static function newProcess(string $root): object
    {
        if (self::$processFactory !== null) {
            return (self::$processFactory)($root);
        }

        // Shell out to a fresh PHP process (fresh autoload) rather than booting
        // the host app in-process - migrations must run against the
        // just-updated code. Composer runs scripts from the project root.
        $process = new Process(
            [PHP_BINARY, 'artisan', 'redline:update', '--force', '--no-interaction'],
            $root,
        );
        $process->setTimeout(self::TIMEOUT);

        return $process;
    }

    /**
     * One streamed STDOUT/STDERR chunk from the spawned update, verbatim.
     */
    private static function streamChunk(string $type, string $data): void
    {
        fwrite($type === Process::ERR ? self::$stderr : self::$stdout, $data);
    }

    /**
     * @return array<int, string>
     */
    private static function outputLines(string $output): array
    {
        return array_values(array_filter(explode("\n", str_replace("\r\n", "\n", $output)), fn (string $line) => trim($line) !== ''));
    }

    private static function write(string $line): void
    {
        fwrite(self::$stdout, $line.PHP_EOL);
    }

    private static function writeError(string $line): void
    {
        fwrite(self::$stderr, $line.PHP_EOL);
    }

    /** @internal test seam: swap the spawned Process for a fake in the suite. */
    public static function setProcessFactory(?callable $processFactory): void
    {
        self::$processFactory = $processFactory;
    }

    /**
     * @internal test seam: redirect the hook's output away from the real
     * STDOUT/STDERR; omitting both arguments restores the streams that PHP
     * defined at startup.
     */
    public static function setOutputStreams($stdout = null, $stderr = null): void
    {
        self::$stdout = $stdout ?? STDOUT;
        self::$stderr = $stderr ?? STDERR;
    }

    private static ?\Closure $processFactory = null;

    private static $stdout = STDOUT;

    private static $stderr = STDERR;
}
