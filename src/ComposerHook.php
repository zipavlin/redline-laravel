<?php

namespace Zipavlin\Redline;

use Symfony\Component\Process\Process;
use Throwable;

/**
 * Composer `post-package-update` listener.
 *
 * `redline:install` (and `redline:update` for pre-existing installs) adds
 * `Zipavlin\Redline\ComposerHook::postPackageUpdate` to the host app's
 * composer.json, so a later `composer update` keeps the installed schema and
 * published assets current without a manual `php artisan redline:update`.
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
     * Composer script entry point. `$event` is Composer's post-package-update
     * event (Composer\Installer\PackageEvent), duck-typed because
     * composer/composer is not a dependency of this package.
     *
     * Never throws and never fails the parent `composer update`: that operation
     * already succeeded, and a failed migration must not turn it into a failure.
     */
    public static function postPackageUpdate(object $event): void
    {
        if (self::shouldUpdate($event)) {
            // Composer runs scripts from the project root.
            self::runUpdate((string) getcwd());
        }
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
     * Shell out to a fresh PHP process (fresh autoload) rather than booting the
     * host app in-process - migrations must run against the just-updated code.
     */
    private static function runUpdate(string $root): void
    {
        if (! is_file($root.DIRECTORY_SEPARATOR.'artisan')) {
            self::write('  • [redline] No artisan file found - skipped the automatic redline:update.');

            return;
        }

        try {
            $process = new Process(
                [PHP_BINARY, 'artisan', 'redline:update', '--force', '--no-interaction'],
                $root,
            );
            $process->setTimeout(self::TIMEOUT);
            $process->run();
        } catch (Throwable $e) {
            self::reportFailure($e->getMessage());

            return;
        }

        if ($process->isSuccessful()) {
            self::write('  ✓ Redline updated (php artisan redline:update --force).');

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
     * @return array<int, string>
     */
    private static function outputLines(string $output): array
    {
        return array_values(array_filter(explode("\n", str_replace("\r\n", "\n", $output)), fn (string $line) => trim($line) !== ''));
    }

    private static function write(string $line): void
    {
        fwrite(STDOUT, $line.PHP_EOL);
    }

    private static function writeError(string $line): void
    {
        fwrite(STDERR, $line.PHP_EOL);
    }
}
