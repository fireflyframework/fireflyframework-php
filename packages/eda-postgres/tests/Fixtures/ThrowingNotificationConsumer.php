<?php

declare(strict_types=1);

namespace Firefly\Eda\Postgres\Tests\Fixtures;

use ErrorException;
use Firefly\Eda\Postgres\PostgresEventConsumer;

/**
 * A PostgresEventConsumer subclass that forces the NOTIFY-wait seam to throw, simulating a host app that converts the
 * deprecated pgsqlGetNotify() E_DEPRECATED into an exception (a hardened error handler, or a test runner with
 * failOnDeprecation). Used to prove poll() swallows ANY awaitNotification() failure and still delivers the row via
 * the poll-fallback PENDING claim — without needing a live pgsql socket.
 */
final class ThrowingNotificationConsumer extends PostgresEventConsumer
{
    protected function awaitNotification(int $timeoutMs): void
    {
        throw new ErrorException('simulated deprecation-to-exception');
    }
}
