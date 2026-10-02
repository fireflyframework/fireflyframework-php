<?php

declare(strict_types=1);

namespace Firefly\FeatureFlags\Tests\Support;

use RuntimeException;

/**
 * Flag documents written to the system temp directory for one test. write() and rewrite() pin the mtime, never the
 * wall clock, so a revision (`mtime-size`) is known in advance and "changed" never depends on a second ticking over.
 * removeAll() deletes every file written since the last call: run it from afterEach(), which runs after a failing
 * test too.
 */
final class TemporaryFlagFiles
{
    /** 2026-01-01T00:00:00Z: the mtime every file is written with unless the test names another. */
    public const int MTIME = 1_767_225_600;

    /** @var list<string> */
    private static array $paths = [];

    public static function write(string $extension, string $contents, int $mtime = self::MTIME): string
    {
        $path = sys_get_temp_dir().'/firefly-flags-'.bin2hex(random_bytes(6)).'.'.$extension;
        self::$paths[] = $path;
        self::rewrite($path, $contents, $mtime);

        return $path;
    }

    /** Replaces the contents of $path and pins its mtime. */
    public static function rewrite(string $path, string $contents, int $mtime = self::MTIME): void
    {
        file_put_contents($path, $contents);
        touch($path, $mtime);
    }

    /**
     * Replaces the contents of $path from a child PHP process, as a deploy would. This process's own file I/O (any
     * open, read, write or flush of a plain file, and touch()) clears PHP's stat cache; a write by another process
     * does not, so a stat of $path cached before this call is stale after it. The mtime is the child's wall clock.
     */
    public static function rewriteFromAnotherProcess(string $path, string $contents): void
    {
        $child = proc_open([PHP_BINARY, '-n', '-r', 'exit(file_put_contents($argv[1], $argv[2]) === false ? 1 : 0);', $path, $contents], [], $pipes);
        if (! is_resource($child) || proc_close($child) !== 0) {
            throw new RuntimeException("A child process could not rewrite [{$path}].");
        }
    }

    public static function removeAll(): void
    {
        foreach (self::$paths as $path) {
            if (is_file($path)) {
                unlink($path);
            }
        }
        self::$paths = [];
    }
}
