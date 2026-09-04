<?php

declare(strict_types=1);

namespace Firefly\Data;

use Firefly\Container\Attributes\Bean;
use Firefly\Container\Attributes\Configuration;
use Firefly\Container\Attributes\Order;
use Firefly\Context\Condition\Attributes\ConditionalOnMissingBean;
use Firefly\Context\Event\ApplicationEventPublisher;
use Firefly\Context\Scan\AppScan;
use Firefly\Data\Domain\AggregateTracker;
use Firefly\Data\Domain\DomainEventDispatcher;
use Firefly\Data\Proxy\ProxyFactory;
use Firefly\Data\Proxy\ProxyMaterializer;
use Firefly\Data\Scanner\TransactionalScanner;
use Firefly\Data\Transaction\TransactionalManifest;
use Firefly\Data\Transaction\TransactionInterceptor;
use Firefly\Data\Transaction\TransactionTemplate;
use Illuminate\Contracts\Container\Container;

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

    /**
     * The #[Transactional] manifest, resolved like every other Category-B artifact: compiled file, then an
     * in-process scan of firefly.scan.paths, then empty.
     *
     * This used to return an unconditional `new TransactionalManifest([], [])`. Nothing anywhere loaded the
     * compiled transactional.php that firefly:cache emits — FireflyCachePaths::TRANSACTIONAL was referenced
     * only by its own declaration — so hasProxyFor() was always false and TransactionalBeanPostProcessor
     * returned every bean unwrapped. #[Transactional] was a silent no-op in any app that did not hand-write
     * its own TransactionalManifest configuration.
     *
     * Proxies are made loadable before the manifest is handed out, because TransactionalBeanPostProcessor
     * fails loud on a manifest that promises a proxy class it cannot find.
     */
    #[Bean]
    #[ConditionalOnMissingBean(TransactionalManifest::class)]
    public function transactionalManifest(Container $container): TransactionalManifest
    {
        if (($file = AppScan::cachedFile($container, AppScan::TRANSACTIONAL)) !== null) {
            ProxyMaterializer::classmap($container);

            return TransactionalManifest::load($file);
        }

        $paths = AppScan::paths($container);
        if ($paths === []) {
            return new TransactionalManifest([], []);
        }

        ProxyMaterializer::materialize($paths);

        return (new TransactionalScanner)->scan($paths);
    }

    #[Bean]
    #[ConditionalOnMissingBean(ProxyFactory::class)]
    public function proxyFactory(): ProxyFactory
    {
        return new ProxyFactory;
    }
}
