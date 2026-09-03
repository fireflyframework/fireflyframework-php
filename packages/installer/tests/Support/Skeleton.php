<?php

declare(strict_types=1);

namespace Firefly\Installer\Tests\Support;

use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;
use SplFileInfo;

/**
 * Test support for driving the archetype machinery against the REAL skeleton/ in this monorepo.
 *
 * Every archetype assertion in ArchetypeTest is only worth anything if the input is the skeleton users
 * actually get. A hand-written fixture of "roughly what create-project produces" would keep passing after
 * someone renames skeleton/resources/views/welcome.blade.php, and `firefly new --api` would quietly stop
 * pruning the welcome page in the field. Copying the real directory instead means a skeleton change that
 * breaks an archetype breaks this suite first.
 */
final class Skeleton
{
    /**
     * The monorepo's skeleton/.
     *
     * Unconditional rather than nullable-with-a-skip: /tests is export-ignored from the firefly/installer
     * tarball, so this suite only ever runs inside the monorepo checkout. A missing skeleton is a broken
     * checkout, and a silently skipped archetype suite would be worse than a loud failure.
     */
    public static function path(): string
    {
        $path = dirname(__DIR__, 4).'/skeleton'; // tests/Support -> tests -> installer -> packages -> root
        if (! is_file($path.'/composer.json')) {
            throw new RuntimeException("The monorepo skeleton is missing at {$path}.");
        }

        return $path;
    }

    /**
     * A fake runner that behaves like `composer create-project`: on that argv, and only that argv, it
     * materialises the skeleton at the target directory the command asked for.
     */
    public static function creatingRunner(string $source): FakeProcessRunner
    {
        /** @param list<string> $command */
        $materialise = static function (array $command) use ($source): void {
            $target = $command[3] ?? null;
            if (($command[1] ?? null) !== 'create-project' || ! is_string($target)) {
                return;
            }
            self::copy($source, $target);
        };

        return new FakeProcessRunner(0, $materialise);
    }

    public static function copy(string $source, string $target): void
    {
        self::directory($target);

        /** @var iterable<SplFileInfo> $entries */
        $entries = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($source, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::SELF_FIRST,
        );
        foreach ($entries as $entry) {
            $destination = $target.'/'.substr($entry->getPathname(), strlen($source) + 1);
            if ($entry->isDir()) {
                self::directory($destination);

                continue;
            }
            self::directory(dirname($destination));
            copy($entry->getPathname(), $destination);
        }
    }

    /**
     * mkdir() only when it is actually missing. `@mkdir()` would do — except that PHPUnit's error handler
     * records diagnostics regardless of the suppression operator, so every already-existing parent turned
     * a green test into a warned one.
     */
    private static function directory(string $path): void
    {
        if (! is_dir($path)) {
            mkdir($path, 0o755, true);
        }
    }

    /**
     * Every file under $directory as a sorted list of project-relative paths — the "file set" an archetype
     * assertion compares.
     *
     * @return list<string>
     */
    public static function files(string $directory): array
    {
        if (! is_dir($directory)) {
            return [];
        }

        $files = [];
        /** @var iterable<SplFileInfo> $entries */
        $entries = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::SELF_FIRST,
        );
        foreach ($entries as $entry) {
            if ($entry->isFile()) {
                $files[] = substr($entry->getPathname(), strlen($directory) + 1);
            }
        }
        sort($files);

        return $files;
    }

    /** @return array<array-key, mixed> */
    public static function manifest(string $directory): array
    {
        $raw = file_get_contents($directory.'/composer.json');
        $decoded = $raw === false ? null : json_decode($raw, true);

        return is_array($decoded) ? $decoded : [];
    }

    /**
     * One `require` / `require-dev` block, narrowed once here so the assertions above stay readable —
     * json_decode() hands back mixed all the way down, and repeating that guard per expectation buries the
     * thing actually being asserted.
     *
     * @return array<string, string>
     */
    public static function requirements(string $directory, string $section = 'require'): array
    {
        $value = self::manifest($directory)[$section] ?? null;
        if (! is_array($value)) {
            return [];
        }

        $map = [];
        foreach ($value as $package => $constraint) {
            if (is_string($package) && is_string($constraint)) {
                $map[$package] = $constraint;
            }
        }

        return $map;
    }

    /**
     * The `extra.firefly` block the archetype stamps on the generated manifest.
     *
     * @return array{archetype: string, capabilities: list<string>}
     */
    public static function stamp(string $directory): array
    {
        $extra = self::manifest($directory)['extra'] ?? null;
        $firefly = is_array($extra) ? ($extra['firefly'] ?? null) : null;
        $firefly = is_array($firefly) ? $firefly : [];

        $archetype = $firefly['archetype'] ?? null;
        $capabilities = [];
        foreach (is_array($firefly['capabilities'] ?? null) ? $firefly['capabilities'] : [] as $id) {
            if (is_string($id)) {
                $capabilities[] = $id;
            }
        }

        return [
            'archetype' => is_string($archetype) ? $archetype : '',
            'capabilities' => $capabilities,
        ];
    }
}
