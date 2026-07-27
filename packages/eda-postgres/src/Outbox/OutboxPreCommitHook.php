<?php

declare(strict_types=1);

namespace Firefly\Eda\Postgres\Outbox;

use Firefly\Cqrs\Correlation\CorrelationContext;
use Firefly\Cqrs\Event\EdaCommandEventPublisher;
use Firefly\Data\Domain\PreCommitEventHook;
use Firefly\Domain\DomainEvent;
use Firefly\Eda\Postgres\PostgresEventPublisher;
use Illuminate\Database\Connection;
use Illuminate\Database\ConnectionResolverInterface;

/**
 * Bridges M8's in-tx pre-commit drain to the Postgres outbox. handle($event, $connection) runs synchronously inside
 * the aggregate's tx (DomainEventDispatcher calls it during the pre-commit drain); for a DomainEvent it resolves the
 * AGGREGATE'S OWN connection ($connection, threaded from the dispatcher — I1) and builds a PostgresEventPublisher on it
 * (emitNotify enabled for pgsql), then delegates to an EdaCommandEventPublisher carrying HandlerManifest::destinations()
 * + CorrelationContext (M5), so the domain->envelope mapping (eventType/payload/per-event destination/transaction_id)
 * is IDENTICAL to the after-commit bridge — but the resulting INSERT is same-tx on the aggregate's connection.
 * Non-DomainEvent objects are ignored (the generic after-commit dispatch still handles them). Reflection-free.
 */
final class OutboxPreCommitHook implements PreCommitEventHook
{
    /** @param array<string,string> $destinations eventClass => destination */
    public function __construct(
        private readonly ConnectionResolverInterface $connections,
        private readonly string $channel,
        private readonly string $defaultDestination,
        private readonly array $destinations,
        private readonly ?CorrelationContext $correlation = null,
    ) {}

    public function handle(object $event, ?string $connection = null): void
    {
        if (! $event instanceof DomainEvent) {
            return;
        }

        $conn = $this->connections->connection($connection); // the aggregate's connection — carries the open tx
        $emitNotify = $conn instanceof Connection && $conn->getDriverName() === 'pgsql';
        $publisher = new PostgresEventPublisher($conn, $this->channel, $emitNotify);

        (new EdaCommandEventPublisher($publisher, $this->defaultDestination, $this->destinations, $this->correlation))
            ->publish($event);
    }
}
