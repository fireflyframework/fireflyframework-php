<?php

declare(strict_types=1);

namespace Firefly\Cqrs;

use Firefly\Config\Config;
use Firefly\Container\Attributes\Bean;
use Firefly\Container\Attributes\Configuration;
use Firefly\Container\Attributes\Order;
use Firefly\Context\Condition\Attributes\ConditionalOnMissingBean;
use Firefly\Context\Condition\Attributes\ConditionalOnProperty;
use Firefly\Cqrs\Cache\CacheQueryCache;
use Firefly\Cqrs\Cache\InvalidationRule;
use Firefly\Cqrs\Cache\NoOpQueryCache;
use Firefly\Cqrs\Cache\QueryCache;
use Firefly\Cqrs\Cache\QueryCacheInvalidator;
use Firefly\Cqrs\Cache\QueryCachePolicies;
use Firefly\Cqrs\Command\CommandBus;
use Firefly\Cqrs\Command\DefaultCommandBus;
use Firefly\Cqrs\Correlation\CorrelationContext;
use Firefly\Cqrs\Event\CommandEventPublisher;
use Firefly\Cqrs\Event\DomainEventBridge;
use Firefly\Cqrs\Event\EdaCommandEventPublisher;
use Firefly\Cqrs\Event\EventFailureStrategy;
use Firefly\Cqrs\Event\NoOpEventPublisher;
use Firefly\Cqrs\Exception\CqrsConfigurationException;
use Firefly\Cqrs\Handler\HandlerManifest;
use Firefly\Cqrs\Handler\HandlerRegistry;
use Firefly\Cqrs\Metrics\CqrsMetrics;
use Firefly\Cqrs\Metrics\NoOpCqrsMetrics;
use Firefly\Cqrs\Query\DefaultQueryBus;
use Firefly\Cqrs\Query\QueryBus;
use Firefly\Cqrs\Security\AllowAllAuthorizer;
use Firefly\Cqrs\Security\CommandAuthorizer;
use Firefly\Cqrs\Security\QueryAuthorizer;
use Firefly\Cqrs\Tracing\CqrsTracing;
use Firefly\Cqrs\Tracing\NoOpCqrsTracing;
use Firefly\Cqrs\Validation\MessageValidator;
use Firefly\Eda\EventPublisher;
use Firefly\Validation\Validator;
use Illuminate\Container\Container;
use Illuminate\Contracts\Cache\Factory as CacheFactory;
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

    /**
     * The tracing seam's default, the same shape as cqrsMetrics(): a NoOp behind #[ConditionalOnMissingBean], so
     * ObservabilityAutoConfiguration's #[Order(500)] TracerCqrsTracing bean — registered before this class's
     * #[Order(1000)] evaluates — makes this one back off, and the buses receive INTERNAL spans without cqrs ever
     * naming observability.
     */
    #[Bean]
    #[ConditionalOnMissingBean(CqrsTracing::class)]
    public function cqrsTracing(): CqrsTracing
    {
        return new NoOpCqrsTracing;
    }

    /**
     * firefly.cqrs.query.cache.provider: `none` (the default — the inert NoOpQueryCache) or `cache`, the
     * CacheQueryCache on the Laravel cache store firefly.cqrs.query.cache.store names ('' = the default store),
     * under the key prefix firefly.cqrs.query.cache.prefix. Anything else refuses the boot rather than silently
     * running uncached. An application that binds its own QueryCache wins, as before.
     */
    #[Bean]
    #[ConditionalOnMissingBean(QueryCache::class)]
    public function queryCache(Config $config, Container $container): QueryCache
    {
        $provider = $config->string('firefly.cqrs.query.cache.provider', 'none');
        if ($provider === 'none') {
            return new NoOpQueryCache;
        }

        if ($provider !== 'cache') {
            throw new CqrsConfigurationException("firefly.cqrs.query.cache.provider must be 'none' or 'cache', got '{$provider}'.");
        }

        /** @var CacheFactory $caches */
        $caches = $container->make('cache');
        $store = $config->string('firefly.cqrs.query.cache.store', '');

        return new CacheQueryCache(
            $caches->store($store === '' ? null : $store),
            $config->string('firefly.cqrs.query.cache.prefix', CacheQueryCache::DEFAULT_PREFIX),
            $this->logger($container),
        );
    }

    /**
     * The invalidator behind #[InvalidatesQuery], bound only with the real cache. Its rules are the compiled
     * attribute rules plus firefly.cqrs.query.cache.invalidation — `event type => [query classes]` — for events this
     * process has no class for (another service's envelope); those evict the queries' whole groups. A malformed
     * entry refuses the boot.
     */
    #[Bean]
    #[ConditionalOnProperty(name: 'firefly.cqrs.query.cache.provider', havingValue: 'cache')]
    #[ConditionalOnMissingBean(QueryCacheInvalidator::class)]
    public function queryCacheInvalidator(QueryCache $cache, HandlerManifest $manifest, Config $config, Container $container): QueryCacheInvalidator
    {
        $rules = $manifest->invalidations();

        foreach ($config->array('firefly.cqrs.query.cache.invalidation', []) as $eventType => $queries) {
            if (! is_string($eventType) || ! is_array($queries)) {
                throw new CqrsConfigurationException('firefly.cqrs.query.cache.invalidation must map an event type to a list of query classes.');
            }

            foreach ($queries as $query) {
                if (! is_string($query) || ! class_exists($query)) {
                    throw new CqrsConfigurationException("firefly.cqrs.query.cache.invalidation.{$eventType} names ".get_debug_type($query).' that is not a query class.');
                }

                $rules[] = new InvalidationRule('', $eventType, $query);
            }
        }

        return new QueryCacheInvalidator($cache, QueryCachePolicies::fromManifest($manifest), $rules, $this->logger($container));
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
        $logger = $this->logger($container);

        return new DomainEventBridge($publisher, $strategy, $logger);
    }

    #[Bean]
    #[ConditionalOnMissingBean(CommandBus::class)]
    public function commandBus(HandlerRegistry $registry, MessageValidator $validator, CommandAuthorizer $authorizer, CorrelationContext $correlation, CqrsMetrics $metrics, ?CqrsTracing $tracing = null): CommandBus
    {
        return new DefaultCommandBus($registry, $validator, $authorizer, $correlation, $metrics, $tracing);
    }

    #[Bean]
    #[ConditionalOnMissingBean(QueryBus::class)]
    public function queryBus(HandlerRegistry $registry, MessageValidator $validator, QueryAuthorizer $authorizer, CorrelationContext $correlation, CqrsMetrics $metrics, QueryCache $cache, Config $config, ?CqrsTracing $tracing = null, ?HandlerManifest $manifest = null): QueryBus
    {
        $ttl = $config->has('firefly.cqrs.query.cache_ttl') ? $config->int('firefly.cqrs.query.cache_ttl') : null;
        $policies = $manifest !== null ? QueryCachePolicies::fromManifest($manifest) : null;

        return new DefaultQueryBus($registry, $validator, $authorizer, $correlation, $metrics, $cache, $ttl, $tracing, $policies);
    }

    private function logger(Container $container): ?LoggerInterface
    {
        if (! $container->bound(LoggerInterface::class)) {
            return null;
        }

        return $container->make(LoggerInterface::class);
    }
}
