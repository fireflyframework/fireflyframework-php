<?php

declare(strict_types=1);

namespace Firefly\Web\Error;

use Throwable;

/**
 * Turning an absolute file name into the short one a person reads — the single highest-value line on the
 * error page.
 *
 * THE BUG THIS EXISTS TO END. The shortening used to be three lines inside ErrorReport: strip $basePath when
 * the file literally starts with it, and give up otherwise. `str_starts_with` is exactly the wrong test for
 * this job, because naming one directory two ways is routine — /var/www symlinked to a release directory, a
 * bind mount inside a container, a test harness whose base path is a vendored application while the frames
 * name the repository it was booted from. In every one of those the prefix misses and NOTHING is shortened,
 * so a hundred trace rows each print /Users/…/vendor/laravel/framework/src/Illuminate/Routing/Route.php,
 * wrap onto three lines at an 87-pixel pitch, and a 500 page measures 10,108 pixels tall. That is not a
 * styling problem and no amount of collapsing frames fixes it.
 *
 * THREE ANSWERS, CHEAPEST FIRST. The literal prefix (a normal deployment: no syscall at all). The prefix
 * normalised through realpath(), computed ONCE for the base rather than once per frame (the symlink, the
 * bind mount). And the roots the trace reveals about ITSELF: everything before a `/vendor/` segment is a
 * Composer project root whatever the application believes its base path to be, which rescues the harness
 * case with no configuration and no filesystem access. Whatever survives all three is cut at the last
 * `/vendor/`, because from there on a path IS the dependency's identity.
 *
 * NOTHING HERE HIDES ANYTHING. Every answer is a PREFIX removal: the path printed is the same path with the
 * part every row shares taken off the front. A reader who needs the absolute name puts the project root
 * back; a reader who needs the file sees the file, on one line, undamaged.
 */
final class SourcePaths
{
    /** How far down the `previous` chain roots are gathered — the same bound ErrorReport::previous() uses. */
    private const int CHAIN = 8;

    /** The directory separators a root may end with, and the vendor markers a path may be cut at. */
    private const array VENDOR_MARKERS = ['/vendor/', '\\vendor\\'];

    /**
     * Every prefix a frame of THIS throwable may be shortened against, longest first.
     *
     * Longest first is the behaviour, not a detail: in a monorepo a frame lives under both the repository
     * and the package, and describing `src/X.php` as `packages/web/src/X.php` buries the part that varies
     * under the part that does not.
     *
     * @return list<string>
     */
    public static function roots(Throwable $e, string $basePath): array
    {
        $roots = [];

        if ($basePath !== '') {
            $roots[] = rtrim($basePath, '/\\').'/';

            // realpath() ONCE, for the base — never per frame, because this runs on a page that is already
            // rendering under duress and a stat per stack frame is a hundred syscalls for cosmetics. It
            // answers false for a path that no longer exists, and a false is simply not a root.
            $real = realpath($basePath);
            if ($real !== false) {
                $roots[] = rtrim($real, '/\\').'/';
            }
        }

        foreach (self::files($e) as $file) {
            foreach (self::VENDOR_MARKERS as $marker) {
                $at = strrpos($file, $marker);
                if ($at !== false) {
                    $roots[] = substr($file, 0, $at + 1);
                }
            }
        }

        $roots = array_values(array_unique($roots));

        usort($roots, static fn (string $a, string $b): int => strlen($b) <=> strlen($a));

        return $roots;
    }

    /**
     * The path as a person reads it: the LONGEST root that prefixes it, removed.
     *
     * Longest rather than first, and decided here rather than left to the order the list arrived in: in a
     * monorepo a frame lives under both the repository and the package, and describing `src/X.php` as
     * `packages/web/src/X.php` buries the part that varies under the part every row shares. roots() already
     * sorts longest-first, but this is a public function that takes any list, and a rule that only holds for
     * one caller's ordering is not a rule.
     *
     * @param  list<string>  $roots
     */
    public static function shorten(string $file, array $roots): string
    {
        if ($file === '') {
            return '';
        }

        $best = '';

        foreach ($roots as $root) {
            // '/' is never a root: it matches every absolute path and would shorten each of them by exactly
            // one character, which is the worst of both answers.
            if ($root === '' || $root === '/' || $root === '\\' || strlen($root) <= strlen($best)) {
                continue;
            }

            if (str_starts_with($file, $root)) {
                $best = $root;
            }
        }

        if ($best !== '') {
            return substr($file, strlen($best));
        }

        foreach (self::VENDOR_MARKERS as $marker) {
            $at = strrpos($file, $marker);
            if ($at !== false) {
                return substr($file, $at + 1);
            }
        }

        return $file;
    }

    /**
     * Every file named by the throwable, its trace and its `previous` chain.
     *
     * The chain is walked because that is where the real cause usually is — firefly/web wraps a binding
     * failure, the container wraps a constructor throw — and its frames are printed on the same page.
     *
     * @return list<string>
     */
    private static function files(Throwable $e): array
    {
        $files = [];
        $seen = 0;
        $current = $e;

        while ($current instanceof Throwable && $seen <= self::CHAIN) {
            $files[] = $current->getFile();

            foreach ($current->getTrace() as $entry) {
                if (is_string($entry['file'] ?? null)) {
                    $files[] = $entry['file'];
                }
            }

            $current = $current->getPrevious();
            $seen++;
        }

        return $files;
    }
}
