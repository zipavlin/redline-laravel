<?php

namespace Zipavlin\Redline\Support;

use Composer\InstalledVersions;

/**
 * The version-drift marker behind the composer `post-autoload-dump` hook
 * (see ComposerHook): `redline:install`/`redline:update` record the installed
 * version after a successful run, and the hook compares the marker - via
 * plain file reads, without booting the host application - before deciding
 * whether `redline:update` needs to spawn again.
 *
 * The drift key is Composer's installed pretty version, not
 * RedlineServiceProvider::getVersion(): the latter mints a fresh UUID for dev
 * installs (an asset cache-busting choice), which would make the marker drift
 * on every single call. The pretty version is the released tag for regular
 * installs and a stable branch name ("dev-main") for path-repo installs.
 */
final class UpdateMarker
{
    private const FILE = 'update-marker.json';

    private const PACKAGE = 'zipavlin/redline';

    /**
     * The drift key when Composer's installed data carries no version for the
     * package. A deterministic sentinel, never RedlineServiceProvider::getVersion():
     * that mints a fresh UUID per call for dev installs, so marker writer and hook
     * reader could never agree and every composer run would spawn an update.
     */
    private const UNREGISTERED = 'unknown';

    /**
     * The marker's path inside a running application (the install/update
     * commands write it there), following the storage path configuration like
     * the rest of Redline's data.
     */
    public static function path(): string
    {
        return storage_path(implode(DIRECTORY_SEPARATOR, [
            'app',
            (string) config('redline.storage.path.base', 'redline'),
            (string) config('redline.storage.path.database', 'database'),
            self::FILE,
        ]));
    }

    /**
     * The marker's path under a HOST project root, for the composer hook,
     * which runs outside any booted application and therefore cannot use
     * storage_path()/config(). Matches the default storage layout; a host
     * that moved Redline's storage elsewhere simply re-runs the update on
     * every composer run.
     */
    public static function pathWithin(string $root): string
    {
        return $root.DIRECTORY_SEPARATOR.'storage'.DIRECTORY_SEPARATOR.'app'
            .DIRECTORY_SEPARATOR.'redline'.DIRECTORY_SEPARATOR.self::FILE;
    }

    /**
     * The key the marker must hold to count as current.
     */
    public static function currentVersionKey(): string
    {
        if (! class_exists(InstalledVersions::class)) {
            return self::UNREGISTERED;
        }

        $version = InstalledVersions::getPrettyVersion(self::PACKAGE);

        if (! is_string($version) || $version === '') {
            return self::UNREGISTERED;
        }

        return $version;
    }

    /**
     * Whether the marker exists and records exactly $key.
     */
    public static function isCurrent(string $root, string $key): bool
    {
        return self::versionAt(self::pathWithin($root)) === $key;
    }

    /**
     * Record $key as the install's current version. Returns false when the
     * marker could not be written - callers treat that as best-effort.
     */
    public static function mark(string $key, ?string $path = null): bool
    {
        $path ??= self::path();

        if (! is_dir(dirname($path))) {
            mkdir(dirname($path), 0755, true);
        }

        $encoded = json_encode(['version' => $key], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        return $encoded !== false
            && file_put_contents($path, $encoded."\n") !== false;
    }

    /**
     * The version the marker records, or null when missing or unreadable.
     */
    private static function versionAt(string $path): ?string
    {
        if (! is_file($path)) {
            return null;
        }

        $decoded = json_decode((string) file_get_contents($path), true);

        return is_array($decoded) && is_string($decoded['version'] ?? null) && $decoded['version'] !== ''
            ? $decoded['version']
            : null;
    }
}
