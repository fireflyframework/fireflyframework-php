<?php

declare(strict_types=1);

namespace Firefly\Data;

use Firefly\Container\Attributes\Bean;
use Firefly\Container\Attributes\Configuration;
use Firefly\Container\Attributes\Order;
use Firefly\Context\Condition\Attributes\ConditionalOnMissingBean;
use Firefly\Context\Event\ApplicationEventPublisher;
use Firefly\Data\Domain\AggregateTracker;
use Firefly\Data\Domain\DomainEventDispatcher;
use Firefly\Data\Proxy\ProxyFactory;
use Firefly\Data\Transaction\TransactionalManifest;
use Firefly\Data\Transaction\TransactionInterceptor;
use Firefly\Data\Transaction\TransactionTemplate;

/**
 * Always-on transaction-engine wiring. #[Order(1000)] places it after user definitions; each bean backs off
 * #[ConditionalOnMissingBean] so an app that supplies its own wins. The default TransactionalManifest is EMPTY —
 * the framework has no #[Transactional] beans of its own; the app's compiled manifest (firefly:cache, M15) or a
 * test's inline manifest overrides it. The TransactionalBeanPostProcessor is a separate #[Component] (discovered
 * by RegisterBeanPostProcessorsPass via its interfaces), not a bean here.
 */
#[Configuration]
#[Order(1000)]
final class DataAutoConfiguration
{
    #[Bean]
    #[ConditionalOnMissingBean(AggregateTracker::class)]
    public function aggregateTracker(): AggregateTracker
    {
        return new AggregateTracker;
    }

    #[Bean]
    #[ConditionalOnMissingBean(DomainEventDispatcher::class)]
    public function domainEventDispatcher(AggregateTracker $tracker, ApplicationEventPublisher $publisher): DomainEventDispatcher
    {
        return new DomainEventDispatcher($tracker, $publisher);
    }

    #[Bean]
    #[ConditionalOnMissingBean(TransactionTemplate::class)]
    public function transactionTemplate(DomainEventDispatcher $dispatcher): TransactionTemplate
    {
        return new TransactionTemplate($dispatcher);
    }

    #[Bean]
    #[ConditionalOnMissingBean(TransactionInterceptor::class)]
    public function transactionInterceptor(TransactionTemplate $template): TransactionInterceptor
    {
        return new TransactionInterceptor($template);
    }

    #[Bean]
    #[ConditionalOnMissingBean(TransactionalManifest::class)]
    public function transactionalManifest(): TransactionalManifest
    {
        return new TransactionalManifest([], []);
    }

    #[Bean]
    #[ConditionalOnMissingBean(ProxyFactory::class)]
    public function proxyFactory(): ProxyFactory
    {
        return new ProxyFactory;
    }
}
