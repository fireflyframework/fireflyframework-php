<?php

declare(strict_types=1);

namespace Firefly\Eda\Postgres;

use Closure;
use Firefly\Eda\Database\DatabaseEventConsumer;
use Firefly\Eda\Database\Outbox\Claim\OutboxClaimStrategy;
use Firefly\Eda\Postgres\Pgsql\NotificationWaiter;
use Illuminate\Database\Connection;

/**
 * firefly/eda-database's DatabaseEventConsumer — the same leased claim, the same guarded ack/nack, the same
 * "no EventPublisher call" terminal path — with Postgres's low-latency wake-up: subscribe() issues `LISTEN <channel>`
 * and the wait between empty claims is a NOTIFY wait (NotificationWaiter) instead of a sleep, so the in-transaction
 * pg_notify PostgresEventPublisher fires on commit wakes the consumer the moment the row is visible. On any other
 * driver (sqlite in tests) there is no LISTEN and no wait. The claim itself is chosen by capability like everywhere
 * else — on pgsql that is SKIP LOCKED inside a short claim transaction, which is what makes several workers safe.
 */
final class PostgresEventConsumer extends DatabaseEventConsumer
{
    /**
     * @param  (Closure(int): void)|null  $awaitNotification  overrides the pgsql NOTIFY wait (a test seam)
     * @param  (Closure(): \DateTimeImmutable)|null  $clock
     */
    public function __construct(
        Connection $connection,
        private readonly string $channel = 'firefly_eda_events',
        int $maxAttempts = 3,
        ?Closure $awaitNotification = null,
        ?OutboxClaimStrategy $claim = null,
        float $leaseSeconds = 300.0,
        ?string $claimant = null,
        ?Closure $clock = null,
    ) {
        parent::__construct(
            $connection,
            $claim,
            $maxAttempts,
            $leaseSeconds,
            claimant: $claimant,
            awaitWork: $awaitNotification ?? static function (int $timeoutMs) use ($connection): void {
                if ($connection->getDriverName() === 'pgsql') {
                    NotificationWaiter::wait($connection->getPdo(), $timeoutMs);
                }
            },
            clock: $clock,
        );
    }

    public function subscribe(array $destinations): void
    {
        if ($this->connection->getDriverName() === 'pgsql') {
            $this->connection->getPdo()->exec('LISTEN '.$this->channel);
        }
    }
}
