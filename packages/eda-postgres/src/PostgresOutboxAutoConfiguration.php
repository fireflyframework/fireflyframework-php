<?php

declare(strict_types=1);

namespace Firefly\Eda\Postgres;

use Firefly\Config\Config;
use Firefly\Container\Attributes\Bean;
use Firefly\Container\Attributes\Configuration;
use Firefly\Container\Attributes\Order;
use Firefly\Context\Condition\Attributes\ConditionalOnProperty;
use Firefly\Context\Event\ApplicationEventPublisher;
use Firefly\Cqrs\Correlation\CorrelationContext;
use Firefly\Cqrs\Event\CommandEventPublisher;
use Firefly\Cqrs\Event\NoOpEventPublisher;
use Firefly\Cqrs\Handler\HandlerManifest;
use Firefly\Data\Domain\AggregateTracker;
use Firefly\Data\Domain\DomainEventDispatcher;
use Firefly\Eda\Bus\SubscriberRegistry;
use Firefly\Eda\Consumer\EventConsumer;
use Firefly\Eda\EventPublisher;
use Firefly\Eda\Postgres\Outbox\OutboxPreCommitHook;
use Illuminate\Database\Connection;
use Illuminate\Database\ConnectionResolverInterface;

/**
 * Wires the GENUINE same-transaction Postgres outbox when firefly.eda.provider=postgres. #[Order(900)] (below
 * DataAutoConfiguration's / CqrsAutoConfiguration's 1000) makes this register FIRST, so:
 *   - eventPublisher() binds EventPublisher -> PostgresEventPublisher (the outbox writer),
 *   - outboxPreCommitHook() binds the in-tx hook (T3's PreCommitEventHook),
 *   - domainEventDispatcher() binds OUR DomainEventDispatcher carrying that hook, winning
 *     DataAutoConfiguration's #[ConditionalOnMissingBean(DomainEventDispatcher::class)], and
 *   - commandEventPublisher() NoOps the AFTER-commit eda leg, winning CqrsAutoConfiguration's
 *     #[ConditionalOnMissingBean(CommandEventPublisher::class)].
 *
 * The last two together are the DOUBLE-PUBLISH AVOIDANCE: a domain event is written to the outbox EXACTLY ONCE —
 * in-tx via the hook (atomic with the aggregate) — and NOT a second time after commit via the M10
 * DomainEventBridge -> CommandEventPublisher -> EdaCommandEventPublisher leg (which would INSERT the same event to
 * the same table again, this time NOT enlisted in the aggregate's tx). All four beans are #[ConditionalOnProperty]-
 * gated on firefly.eda.provider=postgres, so with any other provider (or none) this whole package is INERT: the M8
 * data + M10 cqrs after-commit paths behave exactly as shipped.
 */
#[Configuration]
#[Order(900)]
final class PostgresOutboxAutoConfiguration
{
    #[Bean]
    #[ConditionalOnProperty(name: 'firefly.eda.provider', havingValue: 'postgres')]
    public function subscriberRegistry(): SubscriberRegistry
    {
        return new SubscriberRegistry;
    }

    #[Bean]
    #[ConditionalOnProperty(name: 'firefly.eda.provider', havingValue: 'postgres')]
    public function eventPublisher(Config $config, ConnectionResolverInterface $connections): EventPublisher
    {
        $name = $config->has('firefly.eda.postgres.connection') ? $config->string('firefly.eda.postgres.connection') : null;
        $conn = $connections->connection($name);
        // Narrow to the concrete Connection ONLY to read the driver (getDriverName() is not on ConnectionInterface — B2).
        $emitNotify = $conn instanceof Connection && $conn->getDriverName() === 'pgsql';

        return new PostgresEventPublisher(
            $conn,
            $config->string('firefly.eda.postgres.channel', 'firefly_eda_events'),
            $emitNotify,
        );
    }

    /**
     * The in-tx outbox hook. It resolves the aggregate's OWN connection per-event (I1) and carries
     * HandlerManifest::destinations() + CorrelationContext so the in-tx write preserves per-event destinations +
     * transaction_id — matching the after-commit EdaCommandEventPublisher at CqrsAutoConfiguration.php:116-121 (M5).
     */
    #[Bean]
    #[ConditionalOnProperty(name: 'firefly.eda.provider', havingValue: 'postgres')]
    public function outboxPreCommitHook(ConnectionResolverInterface $connections, Config $config, HandlerManifest $manifest, CorrelationContext $correlation): OutboxPreCommitHook
    {
        return new OutboxPreCommitHook(
            $connections,
            $config->string('firefly.eda.postgres.channel', 'firefly_eda_events'),
            $config->string('firefly.cqrs.default_destination', 'cqrs.events'),
            $manifest->destinations(),
            $correlation,
        );
    }

    /** Our own DomainEventDispatcher carrying the hook — wins DataAutoConfiguration's #[ConditionalOnMissingBean] at Order 900. */
    #[Bean]
    #[ConditionalOnProperty(name: 'firefly.eda.provider', havingValue: 'postgres')]
    public function domainEventDispatcher(AggregateTracker $tracker, ApplicationEventPublisher $publisher, OutboxPreCommitHook $hook): DomainEventDispatcher
    {
        return new DomainEventDispatcher($tracker, $publisher, $hook);
    }

    /** Silence the AFTER-commit eda leg so the event is written to the outbox EXACTLY once (in-tx via the hook). */
    #[Bean]
    #[ConditionalOnProperty(name: 'firefly.eda.provider', havingValue: 'postgres')]
    public function commandEventPublisher(): CommandEventPublisher
    {
        return new NoOpEventPublisher;
    }

    /**
     * The TERMINAL in-process consumer firefly:eda:consume drives in postgres mode: it claims committed PENDING
     * outbox rows (LISTEN/NOTIFY wake + FOR UPDATE SKIP LOCKED), delivers each to the #[EventListener] handlers via
     * the SubscriberRegistry, and marks them PUBLISHED — with NO EventPublisher call, so there is no re-INSERT (B1).
     * Narrows the resolved connection to the concrete type because PostgresEventConsumer needs getPdo()/getDriverName()
     * (not on ConnectionInterface — B2).
     */
    #[Bean]
    #[ConditionalOnProperty(name: 'firefly.eda.provider', havingValue: 'postgres')]
    public function eventConsumer(Config $config, ConnectionResolverInterface $connections): EventConsumer
    {
        $name = $config->has('firefly.eda.postgres.connection') ? $config->string('firefly.eda.postgres.connection') : null;
        $conn = $connections->connection($name);
        assert($conn instanceof Connection); // PostgresEventConsumer needs getPdo()/getDriverName() (B2)

        return new PostgresEventConsumer(
            $conn,
            $config->string('firefly.eda.postgres.channel', 'firefly_eda_events'),
            $config->int('firefly.eda.postgres.max_attempts', 3),
        );
    }
}
