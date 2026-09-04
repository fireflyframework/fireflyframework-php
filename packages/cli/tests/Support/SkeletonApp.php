<?php

declare(strict_types=1);

namespace Firefly\Cli\Tests\Support;

/**
 * Locates and autoloads the SHIPPED skeleton application — `skeleton/app`, the example a developer receives
 * from `composer create-project firefly/skeleton` — so the monorepo suite can compile it with the real
 * `firefly:cache` machinery and serve its routes over the real HTTP pipeline.
 *
 * WHY THIS EXISTS AT ALL. The skeleton has its own tests/ directory, but nothing in the monorepo runs it:
 * phpunit.xml.dist's suite covers the root `tests` directory, every package's own tests and the lumen
 * sample's, and the skeleton is a
 * separate composer package with no vendor/ of its own. So until now the shipped example was verified only
 * by CreateProjectOfflineTest, which is in the `createproject` group — EXCLUDED from the default gate
 * because it shells out to a full composer install — and which asserts only that `routes.php` exists, not
 * that any route answers. A sample slice could therefore rot all the way to a 500 and every gate would stay
 * green. Loading skeleton/app from here puts the shipped example under the same default gate as the
 * framework itself.
 *
 * WHY A DEDICATED AUTOLOADER. The monorepo's composer autoloader claims `App\` for `vendor/laravel/pint/app`
 * — a directory that does not even exist — so `class_exists('App\Http\OrderController')` is false without
 * help, and every scanner in the framework discovers classes through `class_exists()`. Composer's loader
 * returns quietly when it cannot map a class, so an appended loader still gets its turn.
 *
 * IT COEXISTS WITH GeneratedAppAutoloader, which claims the same `App\` prefix for testbench's `app/`
 * directory (where the `make:firefly-*` generators write). Both decline silently for a file they do not
 * have, so the two answer for disjoint sets of classes — which holds only as long as no generator test
 * writes a class whose name collides with a skeleton one. The generator tests use `Stub*`/`Compiled*` names
 * precisely so that stays true: a collision would not fail loudly, it would silently resolve to whichever
 * directory won the race, so the convention is the guard.
 */
final class SkeletonApp
{
    private const string PREFIX = 'App\\';

    private static bool $registered = false;

    /**
     * The PSR-4 map every scanner is handed: the app namespace against the shipped skeleton/app.
     *
     * @return array<string,string>
     */
    public static function psr4(): array
    {
        return [self::PREFIX => self::path()];
    }

    /** packages/cli/tests/Support -> tests -> cli -> packages -> the monorepo root. */
    public static function path(): string
    {
        return dirname(__DIR__, 4).'/skeleton/app';
    }

    /**
     * Builds the sample's schema by RUNNING THE SHIPPED MIGRATION, not by restating it here.
     *
     * App\Orders\OrderRepository is an EloquentRepository over the `orders` table, so the sample resource
     * cannot answer a single request without one — and a hand-written CREATE TABLE in this file would be a
     * second, quietly diverging definition of the schema a real `composer create-project` gets from
     * `artisan migrate`. Executing the migration itself means a column renamed there fails here, which is
     * the whole reason the shipped example is in this suite.
     */
    public static function migrate(): void
    {
        $files = glob(dirname(__DIR__, 4).'/skeleton/database/migrations/*.php') ?: [];

        foreach ($files as $file) {
            // Laravel migrations are anonymous classes extending Migration, which declares neither up() nor
            // down() — the base is a marker and the methods are a convention, so method_exists() is both the
            // guard and the only way to tell static analysis this call is real.
            $migration = require $file;

            if (is_object($migration) && method_exists($migration, 'up')) {
                $migration->up();
            }
        }
    }

    /**
     * A complete, VALID order body for the sample resource — the shape App\Http\OrderRequest documents,
     * with a nested address and two lines.
     *
     * It lives on this class rather than as a global helper function in the Pest file because the whole
     * monorepo suite runs in ONE PHPUnit process (phpunit.xml.dist configures neither ParaTest nor process
     * isolation), so two test files declaring the same global function fatal with "Cannot redeclare
     * function" — the same reasoning that produced ArtisanAssertions and GeneratedApp.
     *
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    public static function orderBody(array $overrides = []): array
    {
        return [
            'customer' => 'Ada Lovelace',
            'email' => 'ada@example.com',
            'shipTo' => [
                'street' => '12 Analytical Way',
                'city' => 'London',
                'postcode' => 'W1A 1AA',
                'country' => 'GB',
            ],
            'lines' => [
                ['sku' => 'WIDGET-1', 'quantity' => 2, 'unitPrice' => 9.5],
                ['sku' => 'GEAR-77', 'quantity' => 1, 'unitPrice' => 3.25],
            ],
            ...$overrides,
        ];
    }

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

            $file = self::path().'/'.str_replace('\\', '/', substr($class, strlen(self::PREFIX))).'.php';
            if (is_file($file)) {
                require $file;
            }
        });
    }
}
