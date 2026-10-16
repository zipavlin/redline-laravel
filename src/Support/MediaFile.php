<?php

namespace Zipavlin\Redline\Support;

/**
 * The file-name rule for stored media rows (screenshots and videos).
 *
 * A stored `path` is a bare name written by StorageProvider when the upload was
 * registered - never a path, never a NUL byte. Every reader that turns a row
 * into a filesystem read runs this guard first, so the traversal rule has ONE
 * home: the HTTP binary endpoints (404 on an unusable name) and the MCP
 * screenshot tool (an actionable tool error) cannot drift apart.
 */
final class MediaFile
{
    /**
     * The plain file name the row may carry, or null when it is unusable.
     */
    public static function plainName(?string $path): ?string
    {
        if ($path === null || $path === '' || $path !== basename($path) || str_contains($path, "\0")) {
            return null;
        }

        return $path;
    }
}
