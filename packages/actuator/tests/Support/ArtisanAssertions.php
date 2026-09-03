<?php

declare(strict_types=1);

namespace Firefly\Actuator\Tests\Support;

use Illuminate\Testing\PendingCommand;

/**
 * PHPStan-safe Artisan assertions for firefly:management:serve's tests.
 *
 * InteractsWithConsole::artisan() is declared `@return PendingCommand|int` — it returns a raw int only when
 * $mockConsoleOutput is disabled, which never happens under this monorepo's FireflyTestCase harness. Rather than
 * widening or suppressing that at six call sites, both members of the real union are handled here so level max sees
 * a fully-typed call in either branch. Deliberately a CLASS rather than a file-local function: the whole monorepo
 * suite runs in one PHPUnit process, so two test files declaring a same-named global function would fatal with
 * "Cannot redeclare function". Mirrors firefly/cli's own Firefly\Cli\Tests\Support\ArtisanAssertions — copied
 * rather than imported, because a package's tests must not depend on a sibling package's test-only autoload.
 */
final class ArtisanAssertions
{
    /**
     * Assert the exit code and every expected output fragment, then RUN the command explicitly so a caller can
     * inspect what it delegated to without depending on PendingCommand's destructor firing first.
     *
     * @param  list<string>  $needles
     */
    public static function outputContains(PendingCommand|int $result, int $exitCode, array $needles): void
    {
        if (! $result instanceof PendingCommand) {
            expect($result)->toBe($exitCode);

            return;
        }

        $result->assertExitCode($exitCode);
        foreach ($needles as $needle) {
            $result->expectsOutputToContain($needle);
        }

        $result->run();
    }
}
