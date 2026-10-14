<?php

namespace Zipavlin\Redline\Support;

/**
 * The Redline `post-package-update` composer script in the HOST app's
 * composer.json.
 *
 * Shared by redline:install (adds it), redline:update (offers it to
 * pre-existing installs) and redline:remove (drops it), so all three agree on
 * one entry and one merge rule. Every write is merge-safe: key order is
 * preserved, Composer's plain-string script form is normalized to a list, and
 * nothing Redline did not write is touched. Matching is case-insensitive, so a
 * hand-edited entry with different casing is still recognized (and still
 * removable).
 */
final class ComposerScripts
{
    public const UPDATE_HOOK = 'Zipavlin\Redline\ComposerHook::postPackageUpdate';

    private const EVENT = 'post-package-update';

    public function __construct(private string $path) {}

    public function hasUpdateHook(): bool
    {
        return $this->containsUpdateHook($this->updateHookEntries($this->read()));
    }

    /**
     * Append the hook to `scripts.post-package-update`.
     *
     * Returns true only when the file actually changed - already present,
     * missing, unreadable and invalid JSON all report false so the caller can
     * decide whether to warn.
     */
    public function addUpdateHook(): bool
    {
        $json = $this->read();

        if ($json === null) {
            return false;
        }

        $entries = $this->updateHookEntries($json);

        if ($this->containsUpdateHook($entries)) {
            return false;
        }

        $scripts = is_array($json['scripts'] ?? null) ? $json['scripts'] : [];
        $scripts[self::EVENT] = [...$entries, self::UPDATE_HOOK];
        $json['scripts'] = $scripts;

        return $this->write($json);
    }

    /**
     * Drop the hook, leaving any other `post-package-update` listener intact.
     *
     * Returns true only when the entry was removed; an absent hook, missing
     * file and invalid JSON all report false.
     */
    public function removeUpdateHook(): bool
    {
        $json = $this->read();

        if ($json === null || ! is_array($json['scripts'] ?? null)) {
            return false;
        }

        $entries = $this->updateHookEntries($json);

        if (! $this->containsUpdateHook($entries)) {
            return false;
        }

        $remaining = array_values(array_filter($entries, fn ($entry) => ! $this->isUpdateHook($entry)));

        return $this->write($this->pruneUpdateHook($json, $remaining));
    }

    /**
     * Write $remaining back as the event's listener list, dropping the event key
     * when nothing is left and the whole scripts key when it became empty - the
     * shape Composer writes for an absent script list.
     *
     * @param  array<string, mixed>  $json
     * @param  array<int, mixed>  $remaining
     * @return array<string, mixed>
     */
    private function pruneUpdateHook(array $json, array $remaining): array
    {
        $scripts = $json['scripts'];

        if ($remaining === []) {
            unset($scripts[self::EVENT]);
        } else {
            $scripts[self::EVENT] = $remaining;
        }

        if ($scripts === []) {
            unset($json['scripts']);
        } else {
            $json['scripts'] = $scripts;
        }

        return $json;
    }

    /**
     * The `scripts.post-package-update` value as a list - Composer accepts a
     * plain string for a single-command script, so callers never branch on it.
     *
     * Entries come back verbatim (not necessarily strings) so that a removal
     * can never drop a listener Redline did not write.
     *
     * @param  array<string, mixed>|null  $json
     * @return array<int, mixed>
     */
    private function updateHookEntries(?array $json): array
    {
        if ($json === null || ! is_array($json['scripts'] ?? null)) {
            return [];
        }

        $value = $json['scripts'][self::EVENT] ?? null;

        if (is_string($value) && $value !== '') {
            return [$value];
        }

        return is_array($value) ? array_values($value) : [];
    }

    /**
     * Case-insensitive: composer.json may spell the FQCN with different casing.
     */
    private function isUpdateHook(mixed $entry): bool
    {
        return is_string($entry) && strtolower($entry) === strtolower(self::UPDATE_HOOK);
    }

    /**
     * @param  array<int, mixed>  $entries
     */
    private function containsUpdateHook(array $entries): bool
    {
        foreach ($entries as $entry) {
            if ($this->isUpdateHook($entry)) {
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
