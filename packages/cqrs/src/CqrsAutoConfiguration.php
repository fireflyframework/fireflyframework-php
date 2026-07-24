<?php

declare(strict_types=1);

namespace Firefly\Cqrs;

use Firefly\Config\Config;
use Firefly\Container\Attributes\Bean;
use Firefly\Container\Attributes\Configuration;
use Firefly\Container\Attributes\Order;
use Firefly\Context\Condition\Attributes\ConditionalOnMissingBean;
use Firefly\Cqrs\Cache\NoOpQueryCache;
use Firefly\Cqrs\Cache\QueryCache;
use Firefly\Cqrs\Command\CommandBus;
use Firefly\Cqrs\Command\DefaultCommandBus;
use Firefly\Cqrs\Correlation\CorrelationContext;
use Firefly\Cqrs\Event\CommandEventPublisher;
use Firefly\Cqrs\Event\DomainEventBridge;
use Firefly\Cqrs\Event\EdaCommandEventPublisher;
use Firefly\Cqrs\Event\EventFailureStrategy;
use Firefly\Cqrs\Event\NoOpEventPublisher;
use Firefly\Cqrs\Handler\HandlerManifest;
use Firefly\Cqrs\Handler\HandlerRegistry;
use Firefly\Cqrs\Metrics\CqrsMetrics;
use Firefly\Cqrs\Metrics\NoOpCqrsMetrics;
use Firefly\Cqrs\Query\DefaultQueryBus;
use Firefly\Cqrs\Query\QueryBus;
use Firefly\Cqrs\Security\AllowAllAuthorizer;
use Firefly\Cqrs\Security\CommandAuthorizer;
use Firefly\Cqrs\Security\QueryAuthorizer;
use Firefly\Cqrs\Validation\MessageValidator;
use Firefly\Eda\EventPublisher;
use Firefly\Validation\Validator;
use Illuminate\Container\Container;
use Psr\Log\LoggerInterface;

/**
 * Always-on cqrs wiring (empty registry is harmless — consistent with eda/data/scheduling; the pyfly
 * conditional_on_property gate is departed from, the `enabled` key reserved). Each bean backs off via
 * #[ConditionalOnMissingBean], so an app binds its own and wins; #[Order(1000)] places it after user definitions.
 * The HandlerRegistry is a shared singleton the CqrsHandlerWiringPass populates at phase 1000 and the buses read.
 * The commandEventPublisher bean gates on an M9 EventPublisher being bound (else NoOp), mirroring how M9 gates a
 * bean on an optional collaborator. Mirrors EdaAutoConfiguration / DataAutoConfiguration.
 */
#[Configuration]
#[Order(1000)]
final class CqrsAutoConfiguration
{
    #[Bean]
    #[ConditionalOnMissingBean(HandlerRegistry::class)]
    public function handlerRegistry(): HandlerRegistry
    {
        return new HandlerRegistry;
    }

    #[Bean]
    #[ConditionalOnMissingBean(CorrelationContext::class)]
    public function correlationContext(): CorrelationContext
    {
        return new CorrelationContext;
    }

    #[Bean]
    #[ConditionalOnMissingBean(MessageValidator::class)]
    public function messageValidator(Container $container): MessageValidator
    {
        if (! $container->bound(Validator::class)) {
            return new MessageValidator(null);
        }

        /** @var Validator $validator */
        $validator = $container->make(Validator::class);

        return new MessageValidator($validator);
    }

    #[Bean]
    #[ConditionalOnMissingBean(CommandAuthorizer::class)]
    public function commandAuthorizer(): CommandAuthorizer
    {
        return new AllowAllAuthorizer;
    }

    #[Bean]
    #[ConditionalOnMissingBean(QueryAuthorizer::class)]
    public function queryAuthorizer(): QueryAuthorizer
    {
        return new AllowAllAuthorizer;
    }

    #[Bean]
    #[ConditionalOnMissingBean(CqrsMetrics::class)]
    public function cqrsMetrics(): CqrsMetrics
    {
        return new NoOpCqrsMetrics;
    }

    #[Bean]
    #[ConditionalOnMissingBean(QueryCache::class)]
    public function queryCache(): QueryCache
    {
        return new NoOpQueryCache;
    }

    #[Bean]
    #[ConditionalOnMissingBean(CommandEventPublisher::class)]
    public function commandEventPublisher(Container $container, Config $config, HandlerManifest $manifest, CorrelationContext $correlation): CommandEventPublisher
    {
        if (! $container->bound(EventPublisher::class)) {
            return new NoOpEventPublisher;
        }

        /** @var EventPublisher $producer */
        $producer = $container->make(EventPublisher::class);

        return new EdaCommandEventPublisher(
            $producer,
            $config->string('firefly.cqrs.default_destination', 'cqrs.events'),
            $manifest->destinations(),
            $correlation,
        );
    }

    #[Bean]
    #[ConditionalOnMissingBean(DomainEventBridge::class)]
    public function domainEventBridge(CommandEventPublisher $publisher, Config $config, Container $container): DomainEventBridge
    {
        $strategy = EventFailureStrategy::tryFrom($config->string('firefly.cqrs.event_failure_strategy', 'log')) ?? EventFailureStrategy::Log;
        $logger = $container->bound(LoggerInterface::class) ? $container->make(LoggerInterface::class) : null;

        return new DomainEventBridge($publisher, $strategy, $logger);
    }

    #[Bean]
    #[ConditionalOnMissingBean(CommandBus::class)]
    public function commandBus(HandlerRegistry $registry, MessageValidator $validator, CommandAuthorizer $authorizer, CorrelationContext $correlation, CqrsMetrics $metrics): CommandBus
    {
        return new DefaultCommandBus($registry, $validator, $authorizer, $correlation, $metrics);
    }

    #[Bean]
    #[ConditionalOnMissingBean(QueryBus::class)]
    public function queryBus(HandlerRegistry $registry, MessageValidator $validator, QueryAuthorizer $authorizer, CorrelationContext $correlation, CqrsMetrics $metrics, QueryCache $cache, Config $config): QueryBus
    {
        $ttl = $config->has('firefly.cqrs.query.cache_ttl') ? $config->int('firefly.cqrs.query.cache_ttl') : null;

        return new DefaultQueryBus($registry, $validator, $authorizer, $correlation, $metrics, $cache, $ttl);
    }
}
