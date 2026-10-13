<?php

declare(strict_types=1);

namespace Firefly\Eda\Database;

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
use Firefly\Eda\Database\Outbox\Claim\OutboxClaimStrategies;
use Firefly\Eda\Database\Outbox\OutboxPreCommitHook;
use Firefly\Eda\EventPublisher;
use Firefly\Eda\Tracing\BrokerTracing;
use Firefly\Eda\Tracing\EdaTracing;
use Illuminate\Database\ConnectionResolverInterface;

/**
 * Wires the portable same-transaction outbox when firefly.eda.provider=database. #[Order(900)] — below
 * DataAutoConfiguration's / CqrsAutoConfiguration's / EdaAutoConfiguration's 1000 — so these register FIRST:
 *   - eventPublisher() binds EventPublisher -> DatabaseEventPublisher (the outbox writer, and the object
 *     EventListenerWiringPass subscribes listeners on — into the shared SubscriberRegistry below);
 *   - outboxPreCommitHook() + domainEventDispatcher() bind OUR DomainEventDispatcher carrying the hook, winning
 *     DataAutoConfiguration's #[ConditionalOnMissingBean(DomainEventDispatcher::class)];
 *   - commandEventPublisher() NoOps the AFTER-commit eda leg, winning CqrsAutoConfiguration's
 *     #[ConditionalOnMissingBean(CommandEventPublisher::class)] — the double-publish avoidance: a domain event is
 *     written EXACTLY ONCE, in the transaction, by the hook;
 *   - eventConsumer() binds the leased DatabaseEventConsumer firefly:eda:consume drives.
 * Every bean is gated on provider=database, so with any other provider this class is inert — and
 * PostgresOutboxAutoConfiguration does the same for provider=postgres from the same building blocks.
 *
 * Nothing here opens a connection: the claim strategy resolves at the first claim (ConfiguredClaimStrategy).
 */
#[Configuration]
#[Order(900)]
final class DatabaseOutboxAutoConfiguration
{
    #[Bean]
    #[ConditionalOnProperty(name: 'firefly.eda.provider', havingValue: 'database')]
    public function subscriberRegistry(): SubscriberRegistry
    {
        return new SubscriberRegistry;
    }

    #[Bean]
    #[ConditionalOnProperty(name: 'firefly.eda.provider', havingValue: 'database')]
    public function eventPublisher(Config $config, ConnectionResolverInterface $connections, SubscriberRegistry $registry, ?EdaTracing $tracing = null): EventPublisher
    {
        $settings = DatabaseOutboxSettings::from($config);

        return new DatabaseEventPublisher(
            $connections->connection($settings->connection),
            $registry,
            $settings->channel,
            BrokerTracing::resolve($config, $tracing),
        );
    }

    #[Bean]
    #[ConditionalOnProperty(name: 'firefly.eda.provider', havingValue: 'database')]
    public function outboxPreCommitHook(ConnectionResolverInterface $connections, Config $config, HandlerManifest $manifest, CorrelationContext $correlation, SubscriberRegistry $registry, ?EdaTracing $tracing = null): OutboxPreCommitHook
    {
        return new OutboxPreCommitHook(
            $connections,
            $registry,
            DatabaseOutboxSettings::from($config)->channel,
            $config->string('firefly.cqrs.default_destination', 'cqrs.events'),
            $manifest->destinations(),
            $correlation,
            BrokerTracing::resolve($config, $tracing),
        );
    }

    #[Bean]
    #[ConditionalOnProperty(name: 'firefly.eda.provider', havingValue: 'database')]
    public function domainEventDispatcher(AggregateTracker $tracker, ApplicationEventPublisher $publisher, OutboxPreCommitHook $hook): DomainEventDispatcher
    {
        return new DomainEventDispatcher($tracker, $publisher, $hook);
    }

    #[Bean]
    #[ConditionalOnProperty(name: 'firefly.eda.provider', havingValue: 'database')]
    public function commandEventPublisher(): CommandEventPublisher
    {
        return new NoOpEventPublisher;
    }

    #[Bean]
    #[ConditionalOnProperty(name: 'firefly.eda.provider', havingValue: 'database')]
    public function eventConsumer(Config $config, ConnectionResolverInterface $connections): EventConsumer
    {
        $settings = DatabaseOutboxSettings::from($config);

        return new DatabaseEventConsumer(
            $settings->connectionFrom($connections),
            OutboxClaimStrategies::configured($settings->claimStrategy),
            $settings->maxAttempts,
            $settings->leaseSeconds,
            $settings->pollIntervalMs,
        );
    }
}
