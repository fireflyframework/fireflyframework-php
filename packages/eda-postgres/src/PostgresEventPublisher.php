<?php

declare(strict_types=1);

namespace Firefly\Eda\Postgres;

use Firefly\Eda\Bus\SubscriberRegistry;
use Firefly\Eda\Database\DatabaseEventPublisher;
use Firefly\Eda\Tracing\EdaTracing;
use Illuminate\Database\ConnectionInterface;

/**
 * The Postgres outbox writer: firefly/eda-database's DatabaseEventPublisher — the same INSERT on the caller's
 * connection, the same traced closure, the same required SubscriberRegistry (see its docblock for why) — plus one
 * statement. When emitNotify is true (the caller computed it from the concrete connection: pgsql), afterInsert() fires
 * `SELECT pg_notify('<channel>', id)` INSIDE THE SAME TRANSACTION: Postgres queues a NOTIFY raised in a transaction and
 * delivers it exactly when that transaction COMMITS, so a consumer's LISTEN wakes the moment the row becomes visible
 * and never for a row that rolled back. The NOTIFY is an optimization only — the consumer's claim is the source of
 * truth and also finds rows written while nobody listened.
 */
final class PostgresEventPublisher extends DatabaseEventPublisher
{
    public function __construct(
        ConnectionInterface $connection,
        SubscriberRegistry $registry,
        string $channel = 'firefly_eda_events',
        private readonly bool $emitNotify = false,
        ?EdaTracing $tracing = null,
    ) {
        parent::__construct($connection, $registry, $channel, $tracing);
    }

    protected function afterInsert(int $id): void
    {
        if ($this->emitNotify) {
            // In-tx pg_notify: Postgres queues it and delivers on COMMIT, so the consumer's LISTEN wakes exactly when
            // the PENDING row becomes visible. (sqlite and every other driver: emitNotify=false.)
            $this->connection->statement('SELECT pg_notify(?, ?)', [$this->channel, (string) $id]);
        }
    }
}
