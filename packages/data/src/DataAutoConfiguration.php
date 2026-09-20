<?php

declare(strict_types=1);

namespace Firefly\Data;

use Firefly\Container\Attributes\Bean;
use Firefly\Container\Attributes\Configuration;
use Firefly\Container\Attributes\Order;
use Firefly\Container\Container as FireflyContainer;
use Firefly\Context\Condition\Attributes\ConditionalOnMissingBean;
use Firefly\Context\Event\ApplicationEventPublisher;
use Firefly\Context\Scan\AppScan;
use Firefly\Data\Domain\AggregateTracker;
use Firefly\Data\Domain\DomainEventDispatcher;
use Firefly\Data\Proxy\AdviceSource;
use Firefly\Data\Proxy\InterceptorRegistry;
use Firefly\Data\Proxy\ProxyFactory;
use Firefly\Data\Proxy\ProxyMaterializer;
use Firefly\Data\Proxy\ProxyPlan;
use Firefly\Data\Proxy\ProxyPlanner;
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
     * Proxies are no longer materialised here: the ProxyPlan bean below owns that, because a class may be
     * proxied for advice this manifest knows nothing about.
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

        return (new TransactionalScanner)->scan($paths);
    }

    /**
     * The proxy plan — which beans get a proxy and which advice each method runs — resolved like every
     * Category-B artifact, compiled first and scanned second:
     *
     *   1. proxy-plan.php, when a compiler wrote one (the classmap autoloader is registered first so the
     *      proxies it names are loadable);
     *   2. else transactional.php, when a firefly:cache from before proxy-plan.php existed wrote one: a
     *      transactional-only plan bridged from the TransactionalManifest bean that loaded it. Such a cache
     *      holds transactional.php and the proxies.php classmap but no plan, and before this bridge existed
     *      the app fell through to the scan below on EVERY boot — reflecting over every class under
     *      firefly.scan.paths and generating proxies into a temp directory (a RuntimeException on a read-only
     *      filesystem) in what used to be a zero-reflection cached boot. A cached app trusts its artifacts; the
     *      compiled proxies in that cache were generated for exactly this transactional-only plan, so nothing
     *      else could be consulted anyway. Every other advice such a plan knows nothing about is a reason to
     *      recompile, which is why firefly/security refuses the boot when its compiled rules sit beside a
     *      cache with no plan (SecurityWiringPass);
     *   3. else an in-process scan of firefly.scan.paths through every AdviceSource bean (development);
     *   4. else — no scan paths at all — a transactional-only plan derived from whatever TransactionalManifest
     *      is bound, so a boot that compiles its manifest by hand (the capstone fixtures) still gets its
     *      #[Transactional] beans wrapped.
     *
     * Every AdviceSource is a #[Component], collected through the Firefly container facade's getAll() (bound
     * at FlushDefinitions, before the post-processor that needs this bean is resolved at phase 700). Data's
     * own TransactionalAdviceSource is always among them; firefly/security adds MethodSecurityAdviceSource.
     * Proxies are materialised BEFORE the plan is handed out, for the same reason the manifest used to do it:
     * TransactionalBeanPostProcessor fails loud on a plan that promises a proxy class it cannot find.
     */
    #[Bean]
    #[ConditionalOnMissingBean(ProxyPlan::class)]
    public function proxyPlan(Container $container, TransactionalManifest $transactional, FireflyContainer $beans): ProxyPlan
    {
        if (($file = AppScan::cachedFile($container, AppScan::PROXY_PLAN)) !== null) {
            ProxyMaterializer::classmap($container);

            return ProxyPlan::load($file);
        }

        if (AppScan::cachedFile($container, AppScan::TRANSACTIONAL) !== null) {
            ProxyMaterializer::classmap($container);

            return ProxyPlan::fromTransactionalManifest($transactional);
        }

        $paths = AppScan::paths($container);
        if ($paths === []) {
            return ProxyPlan::fromTransactionalManifest($transactional);
        }

        $sources = [];
        foreach ($beans->getAll(AdviceSource::class) as $source) {
            if ($source instanceof AdviceSource) {
                $sources[] = $source;
            }
        }

        $planner = new ProxyPlanner($sources);
        $plan = $planner->plan($paths);
        ProxyMaterializer::materialize($planner, $plan);

        return $plan;
    }

    #[Bean]
    #[ConditionalOnMissingBean(InterceptorRegistry::class)]
    public function interceptorRegistry(Container $container): InterceptorRegistry
    {
        return new InterceptorRegistry($container);
    }

    #[Bean]
    #[ConditionalOnMissingBean(ProxyFactory::class)]
    public function proxyFactory(): ProxyFactory
    {
        return new ProxyFactory;
    }
}
