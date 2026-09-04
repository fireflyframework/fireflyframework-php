<?php

declare(strict_types=1);

namespace Firefly\Installer;

use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

/**
 * The small filesystem surface the installer needs. symfony/filesystem would do all of this, but it is a
 * third dependency on a binary whose whole selling point is that a global install pulls two.
 */
final class Filesystem
{
    public static function directoryIsNotEmpty(string $directory): bool
    {
        if (! is_dir($directory)) {
            return false;
        }
        $entries = scandir($directory);

        return $entries !== false && count($entries) > 2; // more than '.' and '..'
    }

    /**
     * Delete everything INSIDE $directory, keeping the directory itself.
     *
     * Keeping the inode matters: the directory may be the process's own cwd (`firefly new . --force`), a
     * mount point, or a path whose permissions/ownership the user set on purpose. Recreating it would
     * silently change all three.
     *
     * Symlinks are unlinked, never followed — RecursiveDirectoryIterator::hasChildren() refuses to descend
     * into a linked directory by default, and the isLink() test below keeps rmdir() away from the target.
     * Without it, `--force` on a directory containing a link to $HOME would empty $HOME.
     */
    public static function emptyDirectory(string $directory): bool
    {
        if (! is_dir($directory)) {
            return true;
        }

        $ok = true;
        /** @var iterable<SplFileInfo> $entries */
        $entries = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($entries as $entry) {
            $path = $entry->getPathname();
            $removed = ! $entry->isLink() && $entry->isDir() ? @rmdir($path) : @unlink($path);
            $ok = $ok && $removed;
        }

        return $ok;
    }

    /**
     * True when emptying this path would be a catastrophe rather than a scaffold.
     *
     * `firefly new ~ --force` and `firefly new / --force` are both typos a shell makes easy, and both would
     * be unrecoverable. The guard is cheap and the false-positive cost is a user having to pick a different
     * directory name; the false-negative cost is their home directory.
     */
    public static function isProtectedPath(string $directory): bool
    {
        $real = realpath($directory);
        if ($real === false) {
            return false; // nothing there to destroy
        }

        if (dirname($real) === $real) {
            return true; // '/' on POSIX, 'C:\' on Windows
        }

        foreach (['HOME', 'USERPROFILE'] as $variable) {
            $home = getenv($variable);
            if (is_string($home) && $home !== '' && realpath($home) === $real) {
                return true;
            }
        }

        return false;
    }

    public static function delete(string $path): bool
    {
        if (is_link($path) || is_file($path)) {
            return @unlink($path);
        }
        if (! is_dir($path)) {
            return true; // already gone
        }

        return self::emptyDirectory($path) && @rmdir($path);
    }

    /**
     * Walk up from $from towards $root removing directories that the prune left empty, so deleting
     * resources/views/welcome.blade.php in the api archetype does not leave an empty resources/views/
     * behind for the user to wonder about. $root itself is never removed.
     */
    public static function pruneEmptyDirectories(string $root, string $from): void
    {
        $root = rtrim($root, '/');
        $current = rtrim($from, '/');

        while ($current !== $root && str_starts_with($current, $root.'/')) {
            if (! is_dir($current) || self::directoryIsNotEmpty($current)) {
                return;
            }
            if (! @rmdir($current)) {
                return;
            }
            $current = dirname($current);
        }
    }

    public static function copy(string $source, string $target): bool
    {
        $directory = dirname($target);
        if (! is_dir($directory) && ! @mkdir($directory, 0o755, true) && ! is_dir($directory)) {
            return false;
        }

        return @copy($source, $target);
    }
}
