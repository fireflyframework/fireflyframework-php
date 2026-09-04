<?php

declare(strict_types=1);

namespace Firefly\Cli\Tests\Support;

use Illuminate\Container\Container;
use ReflectionClass;
use RuntimeException;

/**
 * Filesystem + lint helpers for the tests that generate real code from the `make:firefly-*` stubs and then
 * run the framework's own scanners over the result.
 *
 * A class rather than the global helper functions a Pest file would otherwise declare: the whole monorepo
 * suite runs in ONE PHPUnit process (phpunit.xml.dist configures no ParaTest and no process isolation), so
 * two test files declaring the same global function fatal with "Cannot redeclare function" — the same
 * reasoning that produced ArtisanAssertions.
 */
final class GeneratedApp
{
    /**
     * The PSR-4 map the scanners are handed: the app namespace against testbench's `app/` directory, which
     * is where every generator writes (MakeControllerCommand's `App\Http` sub-namespace included, since the
     * scanners walk recursively).
     *
     * @return array<string,string>
     */
    public static function psr4(): array
    {
        return ['App\\' => self::path()];
    }

    /**
     * Deletes every generated .php file under `app/`, leaving testbench's own .gitkeep skeleton alone.
     *
     * Run before AND after each test: the generators append to a directory shared by every test in the
     * process, and a leftover file from a neighbouring test would be picked up by the recursive scans here
     * and silently change what a scanner returns.
     */
    public static function clean(): void
    {
        $root = self::path();

        foreach (['/*.php', '/*/*.php', '/*/*/*.php'] as $pattern) {
            foreach (glob($root.$pattern) ?: [] as $file) {
                unlink($file);
            }
        }
    }

    /**
     * Asserts the generated file is syntactically valid PHP by running the real `php -l` over it.
     *
     * This is the cheap half of the guarantee; the expensive half is that the callers then hand the file to
     * a scanner, which class_exists()es it and reflects over it. A stub can lint perfectly and still be
     * unusable — the `handle(object $command)` handler stub this suite was written for did exactly that —
     * so a lint on its own is never the whole assertion.
     */
    public static function lint(string $file): void
    {
        expect(is_file($file))->toBeTrue("expected {$file} to have been generated");

        $output = [];
        $status = 0;
        exec(escapeshellarg(PHP_BINARY).' -l '.escapeshellarg($file).' 2>&1', $output, $status);

        expect($status)->toBe(0, "php -l failed for {$file}: ".implode("\n", $output));
    }

    /**
     * A ReflectionClass over a class one of the generators just wrote.
     *
     * The class_exists() guard is not defensive noise. It is what narrows the plain string a test passes in
     * to a class-string for static analysis, and it doubles as an assertion in its own right: an INTERFACE
     * (what repository.stub used to emit) leaves class_exists() false, as does a file PHP cannot load, so
     * either failure stops here with a readable message instead of a reflection error further down.
     *
     * @return ReflectionClass<object>
     */
    public static function reflect(string $class): ReflectionClass
    {
        if (! class_exists($class)) {
            throw new RuntimeException("[{$class}] was not generated as a loadable class.");
        }

        return new ReflectionClass($class);
    }

    /** testbench's `app/` directory for the currently booted application. */
    public static function path(): string
    {
        $path = Container::getInstance()->get('path');

        return is_string($path) ? rtrim($path, '/') : '';
    }
}
