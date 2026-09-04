<?php

declare(strict_types=1);

namespace Firefly\Cli\Tests\Support;

use Illuminate\Container\Container;

/**
 * A PSR-4 fallback autoloader for the `App\` classes the `make:firefly-*` generators write into
 * testbench's `app/` directory during a test run.
 *
 * This exists because the monorepo's composer autoloader already claims the `App\` prefix — it is mapped to
 * `vendor/laravel/pint/app`, a dev-tool artefact — so `class_exists('App\Whatever')` resolves against Pint's
 * source tree, finds nothing, and returns false. Every Firefly scanner discovers classes through
 * `class_exists()` (ComponentScanner, RouteScanner, HandlerScanner, EventListenerScanner,
 * MessageListenerScanner and ConfigPropertiesScanner all share that idiom), so without this fallback a test
 * that generates from a stub and then scans the result would see an EMPTY scan and pass for entirely the
 * wrong reason — it would assert nothing about the generated code at all.
 *
 * Composer's own loader returns quietly when it cannot map a class, so a later-registered autoloader still
 * gets its turn; this one is appended and only ever answers for `App\`.
 *
 * The app path is resolved lazily, per lookup, because the testbench application does not exist yet when
 * the test file is loaded — and it is resolved defensively (the `path` binding may be absent if some other
 * test in the same process touches an `App\` class outside a booted app), in which case this loader simply
 * declines and behaves exactly as if it were not registered.
 */
final class GeneratedAppAutoloader
{
    private const string PREFIX = 'App\\';

    private static bool $registered = false;

    /** Idempotent: several test files may call this, but the loader must only be appended once. */
    public static function register(): void
    {
        if (self::$registered) {
            return;
        }

        self::$registered = true;

        spl_autoload_register(static function (string $class): void {
            if (! str_starts_with($class, self::PREFIX)) {
                return;
            }

            $root = self::appPath();
            if ($root === null) {
                return;
            }

            $file = $root.'/'.str_replace('\\', '/', substr($class, strlen(self::PREFIX))).'.php';
            if (is_file($file)) {
                require $file;
            }
        });
    }

    /** The booted application's `app/` directory, or null when there is no application to ask. */
    private static function appPath(): ?string
    {
        $container = Container::getInstance();
        if (! $container->bound('path')) {
            return null;
        }

        $path = $container->get('path');

        return is_string($path) ? rtrim($path, '/') : null;
    }
}
