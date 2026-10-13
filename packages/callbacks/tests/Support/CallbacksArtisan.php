<?php

declare(strict_types=1);

namespace Firefly\Callbacks\Tests\Support;

use Illuminate\Testing\PendingCommand;
use LogicException;

/**
 * PHPStan-safe entry to the firefly:callbacks:* command tests. InteractsWithConsole::artisan() is declared
 * `PendingCommand|int` (a raw int only when console output mocking is off, never under FireflyTestCase), so the
 * union is narrowed once here and every call site keeps chaining Laravel's own expectations (expectsTable,
 * expectsOutputToContain, assertExitCode) — firefly/messaging's MessagingArtisan precedent.
 */
final class CallbacksArtisan
{
    public static function pending(PendingCommand|int $result): PendingCommand
    {
        if (! $result instanceof PendingCommand) {
            throw new LogicException('Console output mocking is off; the callbacks command tests need a PendingCommand.');
        }

        return $result;
    }
}
