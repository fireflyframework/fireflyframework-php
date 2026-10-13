<?php

declare(strict_types=1);

namespace Firefly\Eda\Postgres\Outbox;

use Firefly\Eda\Database\DatabaseEventPublisher;
use Firefly\Eda\Database\Outbox\OutboxPreCommitHook as DatabaseOutboxPreCommitHook;
use Firefly\Eda\Postgres\PostgresEventPublisher;
use Illuminate\Database\Connection;
use Illuminate\Database\ConnectionInterface;

/**
 * firefly/eda-database's pre-commit hook with the Postgres writer: on a pgsql connection the per-event
 * PostgresEventPublisher also fires the in-transaction pg_notify. Same constructor, same mapping, same tracing and
 * registry rules — see the parent's docblock.
 */
final class OutboxPreCommitHook extends DatabaseOutboxPreCommitHook
{
    protected function writerFor(ConnectionInterface $connection): DatabaseEventPublisher
    {
        $emitNotify = $connection instanceof Connection && $connection->getDriverName() === 'pgsql';

        return new PostgresEventPublisher($connection, $this->registry, $this->channel, $emitNotify, $this->tracing);
    }
}
