<?php

declare(strict_types=1);

namespace Firefly\Cli\Tests\Support;

use RuntimeException;

/**
 * Builds the one state a developer cannot get out of by hand: a compiled manifest naming a #[Component]
 * whose file has since been deleted.
 *
 * The manifest is compiled by a SUBPROCESS, and that is the whole reason this class exists rather than a
 * few lines in the test. Every Firefly scanner discovers classes through class_exists(), which autoloads —
 * so compiling in-process would declare the class this test then has to pretend was deleted, and PHP never
 * forgets a declared class. The test would assert against a manifest whose "missing" entry class_exists()
 * happily answers true for, and would pass without exercising a single line of the fix. A separate PHP
 * process compiles, exits, and takes the declaration with it; the parent process registers an autoloader
 * that can no longer find the file, which is exactly what the developer's process saw.
 *
 * The fixture is deliberately the shape that broke: a #[Primary] implementation of an interface, a second
 * implementation that survives, and a consumer injecting the interface. Deleting the #[Primary] one is what
 * made ContainerRegistrar::wireInterfaces() bind the contract to a class that was not there, so the throw
 * came out of resolving the consumer — a bean nobody had touched — instead of the definition that was stale.
 */
final class StaleApp
{
    public const string NAMESPACE = 'FireflyStaleFixture\\';

    /** The class the fixture deletes after compiling. */
    public const string GHOST = 'FireflyStaleFixture\\StaleGhost';

    public const string SURVIVOR = 'FireflyStaleFixture\\StaleSurvivor';

    public const string CONSUMER = 'FireflyStaleFixture\\StaleConsumer';

    public const string CONTRACT = 'FireflyStaleFixture\\StaleContract';

    private static ?string $root = null;

    /** Registered once per process; declines for every other namespace, exactly as GeneratedAppAutoloader does. */
    private static bool $registered = false;

    /**
     * Writes the fixture, compiles its manifests in a subprocess, deletes the #[Primary] implementation, and
     * returns the source and cache directories. Idempotent per process — the build is the expensive part.
     *
     * @return array{src: string, cache: string}
     */
    public static function prepare(): array
    {
        self::register();

        if (self::$root === null) {
            self::$root = sys_get_temp_dir().'/firefly-stale-'.bin2hex(random_bytes(6));
            self::write();
            self::compile();
            unlink(self::$root.'/src/StaleGhost.php');
        }

        return ['src' => self::$root.'/src', 'cache' => self::$root.'/cache'];
    }

    /** A pristine copy of the STALE manifests, so each test boots from the same artifacts firefly:cache rewrites. */
    public static function restoreStaleCache(): void
    {
        ['cache' => $cache] = self::prepare();

        // firefly:clear deletes the whole directory, so the restore has to be able to put it back.
        if (! is_dir($cache)) {
            mkdir($cache, 0o755, true);
        }

        foreach (glob((string) self::$root.'/stale/*.php') ?: [] as $file) {
            copy($file, $cache.'/'.basename($file));
        }
    }

    private static function register(): void
    {
        if (self::$registered) {
            return;
        }

        self::$registered = true;

        spl_autoload_register(static function (string $class): void {
            if (! str_starts_with($class, self::NAMESPACE) || self::$root === null) {
                return;
            }

            $file = self::$root.'/src/'.str_replace('\\', '/', substr($class, strlen(self::NAMESPACE))).'.php';
            if (is_file($file)) {
                require $file;
            }
        });
    }

    private static function write(): void
    {
        $src = (string) self::$root.'/src';
        mkdir($src, 0o755, true);

        file_put_contents($src.'/StaleContract.php', <<<'PHP'
            <?php

            declare(strict_types=1);

            namespace FireflyStaleFixture;

            interface StaleContract
            {
                public function name(): string;
            }

            PHP);

        file_put_contents($src.'/StaleGhost.php', <<<'PHP'
            <?php

            declare(strict_types=1);

            namespace FireflyStaleFixture;

            use Firefly\Container\Attributes\Component;
            use Firefly\Container\Attributes\Primary;

            #[Component]
            #[Primary]
            final class StaleGhost implements StaleContract
            {
                public function name(): string
                {
                    return 'ghost';
                }
            }

            PHP);

        file_put_contents($src.'/StaleSurvivor.php', <<<'PHP'
            <?php

            declare(strict_types=1);

            namespace FireflyStaleFixture;

            use Firefly\Container\Attributes\Component;

            #[Component]
            final class StaleSurvivor implements StaleContract
            {
                public function name(): string
                {
                    return 'survivor';
                }
            }

            PHP);

        file_put_contents($src.'/StaleConsumer.php', <<<'PHP'
            <?php

            declare(strict_types=1);

            namespace FireflyStaleFixture;

            use Firefly\Container\Attributes\Component;

            #[Component]
            final class StaleConsumer
            {
                public function __construct(public readonly StaleContract $contract) {}
            }

            PHP);
    }

    /** Runs the real ManifestCacheWriter — what `firefly:cache` itself drives — in its own PHP process. */
    private static function compile(): void
    {
        $root = (string) self::$root;
        $autoload = dirname(__DIR__, 4).'/vendor/autoload.php';

        $script = $root.'/compile.php';
        file_put_contents($script, sprintf(
            <<<'PHP'
                <?php

                declare(strict_types=1);

                require %s;

                $src = %s;

                spl_autoload_register(static function (string $class) use ($src): void {
                    if (! str_starts_with($class, 'FireflyStaleFixture\\')) {
                        return;
                    }

                    $file = $src.'/'.str_replace('\\', '/', substr($class, strlen('FireflyStaleFixture\\'))).'.php';
                    if (is_file($file)) {
                        require $file;
                    }
                });

                (new Firefly\Cli\Cache\ManifestCacheWriter)->write(['FireflyStaleFixture\\' => $src], %s);

                PHP,
            var_export($autoload, true),
            var_export($root.'/src', true),
            var_export($root.'/cache', true),
        ));

        $output = [];
        $status = 0;
        exec(escapeshellarg(PHP_BINARY).' '.escapeshellarg($script).' 2>&1', $output, $status);

        if ($status !== 0) {
            throw new RuntimeException("compiling the stale fixture failed: \n".implode("\n", $output));
        }

        // Keep the pristine stale copy: the command under test rewrites the cache directory, and every test
        // in the file has to start from the same broken artifacts.
        mkdir($root.'/stale', 0o755, true);
        foreach (glob($root.'/cache/*.php') ?: [] as $file) {
            copy($file, $root.'/stale/'.basename($file));
        }
    }
}
