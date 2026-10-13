<?php

declare(strict_types=1);

namespace Firefly\Messaging\Tests\Support;

use Illuminate\Testing\PendingCommand;

/**
 * PHPStan-safe assertion for the firefly:messaging:consume tests. InteractsWithConsole::artisan() is declared
 * `PendingCommand|int` (a raw int only when console output mocking is off, never under FireflyTestCase), so both
 * members of the union are handled here instead of at each call site — firefly/cli's ArtisanAssertions precedent.
 * The pending command is run() explicitly, so the assertions after the call observe a finished run rather than
 * depending on when the temporary is destructed.
 */
final class MessagingArtisan
{
    public static function outputContains(PendingCommand|int $result, int $exitCode, string $needle): void
    {
        if ($result instanceof PendingCommand) {
            $result->expectsOutputToContain($needle)->assertExitCode($exitCode)->run();

            return;
        }

        expect($result)->toBe($exitCode);
    }
}
