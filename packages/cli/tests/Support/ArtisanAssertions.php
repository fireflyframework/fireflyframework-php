<?php

declare(strict_types=1);

namespace Firefly\Cli\Tests\Support;

use Illuminate\Testing\PendingCommand;

/**
 * Shared PHPStan-safe Artisan assertion helpers for firefly/cli's console tests.
 *
 * InteractsWithConsole::artisan() is declared `@return PendingCommand|int` (it returns a raw int only
 * when $mockConsoleOutput is disabled — never the case under this monorepo's FireflyTestCase harness).
 * Rather than widening or suppressing the return type at each call site, these helpers exhaustively
 * handle BOTH members of that real union so PHPStan (level max) sees a fully-typed, non-suppressed
 * call in either branch.
 *
 * First established as a file-local function in ClearCommandTest (T4). Hoisted here as a reusable,
 * PSR-4-autoloaded class instead of a bare global function: two test files each declaring a same-named
 * global `function assertArtisanExitCode()` would fatal with "Cannot redeclare function" the moment
 * both are loaded into the single PHPUnit process the whole monorepo suite runs in (phpunit.xml.dist
 * has no ParaTest/process-isolation config) — a class avoids that collision entirely.
 */
final class ArtisanAssertions
{
    /** Assert the exit code alone (no output expectations). */
    public static function exitCode(PendingCommand|int $result, int $exitCode): void
    {
        if ($result instanceof PendingCommand) {
            $result->assertExitCode($exitCode);

            return;
        }

        expect($result)->toBe($exitCode);
    }

    /** Assert the exit code AND that the rendered output contains $needle. */
    public static function outputContains(PendingCommand|int $result, int $exitCode, string $needle): void
    {
        if ($result instanceof PendingCommand) {
            $result->assertExitCode($exitCode)->expectsOutputToContain($needle);

            return;
        }

        expect($result)->toBe($exitCode);
    }
}
