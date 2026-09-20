<?php

declare(strict_types=1);

namespace Firefly\Data\Transaction;

use Firefly\Container\Attributes\Component;
use Firefly\Context\Processor\BeanPostProcessor;
use Firefly\Data\Proxy\Advice;
use Firefly\Data\Proxy\InterceptorRegistry;
use Firefly\Data\Proxy\ProxyFactory;
use Firefly\Data\Proxy\ProxyPlan;
use Firefly\Kernel\Exception\Framework\ConfigurationException;

/**
 * Swaps a planned bean for its generated proxy via the M4 seam. beforeInitialization returns the bean
 * unchanged; afterInitialization (pass 2, after #[PostConstruct] ran on the real bean) returns the proxy when
 * the ProxyPlan has one for $declaredClass, else the bean. It is a #[Component] whose class_implements includes
 * BeanPostProcessor, so RegisterBeanPostProcessorsPass discovers + installs it at phase 700.
 *
 * It reads the PLAN, not the TransactionalManifest: since the interceptor chain a class may be proxied for
 * #[PreAuthorize] alone, and the plan is the one artifact that knows every advice a class runs. Each advice's
 * interceptor is resolved through the InterceptorRegistry at wrap time — the transactional one is always
 * bound; an advice whose capability is off degrades to a pass-through link. The proxy class is an app artifact
 * loaded by autoload (firefly:cache) or materialised in-process before this runs.
 */
#[Component]
final class TransactionalBeanPostProcessor implements BeanPostProcessor
{
    public function __construct(
        private readonly ProxyPlan $plan,
        private readonly ProxyFactory $factory,
        private readonly TransactionInterceptor $interceptor,
        private readonly InterceptorRegistry $interceptors,
    ) {}

    public function beforeInitialization(object $bean, string $declaredClass): object
    {
        return $bean;
    }

    public function afterInitialization(object $bean, string $declaredClass): object
    {
        if (! $this->plan->hasProxyFor($declaredClass)) {
            return $bean;
        }

        $proxyClass = $this->plan->proxyClassFor($declaredClass);

        // Both classes are loaded by the time this runs: $bean IS-A $declaredClass, and the generated
        // $proxyClass is autoloaded by firefly:cache (or materialised in-process) before the container resolves
        // the bean. class_exists() narrows the two plain strings to the class-string ProxyFactory::wrap()
        // requires; a plan that promises a proxy whose class is not loaded is a real misconfiguration, so fail
        // loud rather than silently skip interception.
        if (! class_exists($declaredClass) || ! class_exists($proxyClass)) {
            throw new ConfigurationException("Proxy [{$proxyClass}] for [{$declaredClass}] is not loaded. Run firefly:cache to generate it.");
        }

        $links = [];
        foreach ($this->plan->adviceFor($declaredClass) as $id => $advice) {
            if ($id !== Advice::TRANSACTIONAL) {
                $links[$id] = $this->interceptors->for($advice);
            }
        }

        return $this->factory->wrap($bean, $declaredClass, $proxyClass, $this->interceptor, $links);
    }
}
