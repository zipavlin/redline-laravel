<?php

namespace Zipavlin\Redline\Support;

/**
 * The Redline composer script entries in the HOST app's composer.json.
 *
 * Shared by redline:install (migrates the hook in), redline:update (migrates
 * it for pre-existing installs) and redline:remove (drops it), so all three
 * commands agree on one entry shape and one merge rule. Every write is
 * merge-safe: key order is preserved, Composer's plain-string script form is
 * normalized to a list, and nothing Redline did not write is touched.
 * Matching is case-insensitive, so a hand-edited entry with different casing
 * is still recognized (and still removable).
 */
final class ComposerScripts
{
    /**
     * The legacy per-package entry. Kept for hosts still carrying it until
     * their first migrated `redline:install` / `redline:update` run; it keeps
     * working through ComposerHook::postPackageUpdate.
     */
    public const UPDATE_HOOK = 'Zipavlin\Redline\ComposerHook::postPackageUpdate';

    /**
     * The per-run entry written by migrateHooks(), echoing once per composer
     * run instead of once per updated package.
     */
    public const AUTOLOAD_HOOK = 'Zipavlin\Redline\ComposerHook::ensureCurrent';

    private const UPDATE_EVENT = 'post-package-update';

    private const AUTOLOAD_EVENT = 'post-autoload-dump';

    public function __construct(private string $path) {}

    public function hasUpdateHook(): bool
    {
        return $this->hasHook($this->read(), self::UPDATE_EVENT, self::UPDATE_HOOK);
    }

    public function hasAutoloadHook(): bool
    {
        return $this->hasHook($this->read(), self::AUTOLOAD_EVENT, self::AUTOLOAD_HOOK);
    }

    /**
     * Move the Redline hook from the legacy `post-package-update` event to the
     * per-run `post-autoload-dump` event: drop any Redline legacy entry
     * (foreign listeners stay) and append the autoload entry when absent,
     * preserving the default Laravel listeners Redline never wrote. One write
     * even when both sides change; false when nothing changed, the file is
     * missing or the JSON is invalid.
     */
    public function migrateHooks(): bool
    {
        $json = $this->read();

        if ($json === null) {
            return false;
        }

        $before = is_array($json['scripts'] ?? null) ? $json['scripts'] : [];

        $scripts = $this->withoutHook($before, self::UPDATE_EVENT, self::UPDATE_HOOK);
        $scripts = $this->withHook($scripts, self::AUTOLOAD_EVENT, self::AUTOLOAD_HOOK);

        if ($scripts === $before) {
            return false;
        }

        if ($scripts === []) {
            unset($json['scripts']);
        } else {
            $json['scripts'] = $scripts;
        }

        return $this->write($json);
    }

    /**
     * Drop the Redline `post-package-update` entry, leaving any other listener
     * of that event intact. Returns true only when the entry was removed; an
     * absent hook, missing file and invalid JSON all report false.
     */
    public function removeUpdateHook(): bool
    {
        return $this->removeHook(self::UPDATE_EVENT, self::UPDATE_HOOK);
    }

    /**
     * Drop the per-run `post-autoload-dump` entry (redline:remove).
     */
    public function removeAutoloadHook(): bool
    {
        return $this->removeHook(self::AUTOLOAD_EVENT, self::AUTOLOAD_HOOK);
    }

    private function removeHook(string $event, string $hook): bool
    {
        $json = $this->read();

        if ($json === null || ! is_array($json['scripts'] ?? null)) {
            return false;
        }

        $scripts = $this->withoutHook($json['scripts'], $event, $hook);

        if ($scripts === $json['scripts']) {
            return false;
        }

        if ($scripts === []) {
            unset($json['scripts']);
        } else {
            $json['scripts'] = $scripts;
        }

        return $this->write($json);
    }

    private function hasHook(?array $json, string $event, string $hook): bool
    {
        if ($json === null || ! is_array($json['scripts'] ?? null)) {
            return false;
        }

        return $this->contains($this->entriesFor($json['scripts'], $event), $hook);
    }

    /**
     * Listeners left after removing Redline's $hook from $event; the event key
     * is dropped entirely when nothing remains - the shape Composer writes for
     * an absent script. Nothing to remove leaves $scripts untouched.
     *
     * @param  array<string, mixed>  $scripts
     * @return array<string, mixed>
     */
    private function withoutHook(array $scripts, string $event, string $hook): array
    {
        $entries = $this->entriesFor($scripts, $event);

        $remaining = array_values(array_filter($entries, fn ($entry) => ! $this->matches($entry, $hook)));

        if ($remaining === $entries) {
            return $scripts;
        }

        if ($remaining === []) {
            unset($scripts[$event]);
        } else {
            $scripts[$event] = $remaining;
        }

        return $scripts;
    }

    /**
     * Append $hook to $event's listener list when absent; the plain-string
     * script form normalizes to a list along the way.
     *
     * @param  array<string, mixed>  $scripts
     * @return array<string, mixed>
     */
    private function withHook(array $scripts, string $event, string $hook): array
    {
        if ($this->contains($this->entriesFor($scripts, $event), $hook)) {
            return $scripts;
        }

        $scripts[$event] = array_merge($this->entriesFor($scripts, $event), [$hook]);

        return $scripts;
    }

    /**
     * The event's listener value as a list - Composer accepts a plain string
     * for a single-command script, so callers never branch on it. Entries come
     * back verbatim (not necessarily strings) so that a removal can never drop
     * a listener Redline did not write.
     *
     * @param  array<string, mixed>  $scripts
     * @return array<int, mixed>
     */
    private function entriesFor(array $scripts, string $event): array
    {
        $value = $scripts[$event] ?? null;

        if (is_string($value) && $value !== '') {
            return [$value];
        }

        return is_array($value) ? array_values($value) : [];
    }

    /**
     * Case-insensitive: composer.json may spell the FQCN with different casing.
     */
    private function matches(mixed $entry, string $hook): bool
    {
        return is_string($entry) && strtolower($entry) === strtolower($hook);
    }

    /**
     * @param  array<int, mixed>  $entries
     */
    private function contains(array $entries, string $hook): bool
    {
        foreach ($entries as $entry) {
            if ($this->matches($entry, $hook)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array<string, mixed>|null null when the file is missing or not a JSON object
     */
    private function read(): ?array
    {
        if (! is_file($this->path)) {
            return null;
        }

        $decoded = json_decode((string) file_get_contents($this->path), true);

        return is_array($decoded) ? $decoded : null;
    }

    /**
     * @param  array<string, mixed>  $json
     */
    private function write(array $json): bool
    {
        $encoded = json_encode($json, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        return $encoded !== false
            && file_put_contents($this->path, $encoded."\n") !== false;
    }
}
