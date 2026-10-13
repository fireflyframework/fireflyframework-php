<?php

declare(strict_types=1);

namespace Firefly\Eda\Database\Outbox;

use Firefly\Cqrs\Correlation\CorrelationContext;
use Firefly\Cqrs\Event\EdaCommandEventPublisher;
use Firefly\Data\Domain\PreCommitEventHook;
use Firefly\Domain\DomainEvent;
use Firefly\Eda\Bus\SubscriberRegistry;
use Firefly\Eda\Database\DatabaseEventPublisher;
use Firefly\Eda\Tracing\EdaTracing;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\ConnectionResolverInterface;

/**
 * Bridges firefly/data's in-transaction pre-commit drain to the outbox. DomainEventDispatcher calls handle($event,
 * $connection) synchronously inside the aggregate's transaction; for a DomainEvent the hook resolves the AGGREGATE'S
 * OWN connection (threaded from the dispatcher, never a hard-coded default), builds an outbox writer on it, and
 * delegates to an EdaCommandEventPublisher carrying HandlerManifest::destinations() and the CorrelationContext — so
 * the row it writes is byte-for-byte the envelope the after-commit bridge would have produced, but INSERTed in the
 * transaction that is still open. Anything that is not a DomainEvent is ignored.
 *
 * Under an outbox provider the after-commit eda leg is NoOp'd, so this hook is the ONLY writer of a DomainEvent's
 * row: the EdaTracing it carries (the gated one, BrokerTracing::resolve()) decides whether the flagship rows carry a
 * traceparent. The SubscriberRegistry is the app's shared singleton, passed through so no second registry exists.
 *
 * Not `final`: the Postgres hook overrides writerFor() to add the in-transaction pg_notify.
 */
class OutboxPreCommitHook implements PreCommitEventHook
{
    /** @param array<string,string> $destinations eventClass => destination */
    public function __construct(
        protected readonly ConnectionResolverInterface $connections,
        protected readonly SubscriberRegistry $registry,
        protected readonly string $channel,
        protected readonly string $defaultDestination,
        protected readonly array $destinations,
        protected readonly ?CorrelationContext $correlation = null,
        protected readonly ?EdaTracing $tracing = null,
    ) {}

    public function handle(object $event, ?string $connection = null): void
    {
        if (! $event instanceof DomainEvent) {
            return;
        }

        // The aggregate's connection — the one carrying the open transaction.
        $publisher = $this->writerFor($this->connections->connection($connection));

        (new EdaCommandEventPublisher($publisher, $this->defaultDestination, $this->destinations, $this->correlation))
            ->publish($event);
    }

    /** The writer for one event, on the aggregate's own connection. */
    protected function writerFor(ConnectionInterface $connection): DatabaseEventPublisher
    {
        return new DatabaseEventPublisher($connection, $this->registry, $this->channel, $this->tracing);
    }
}
