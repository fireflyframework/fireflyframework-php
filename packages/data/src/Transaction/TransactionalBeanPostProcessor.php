<?php

declare(strict_types=1);

namespace Firefly\Data\Transaction;

use Firefly\Container\Attributes\Component;
use Firefly\Context\Processor\BeanPostProcessor;
use Firefly\Data\Proxy\ProxyFactory;
use Firefly\Kernel\Exception\Framework\ConfigurationException;

/**
 * Swaps a #[Transactional] bean for its generated proxy via the M4 seam. beforeInitialization returns the bean
 * unchanged; afterInitialization (pass 2, after #[PostConstruct] ran on the real bean) returns the proxy when the
 * manifest has one for $declaredClass, else the bean. It is a #[Component] whose class_implements includes
 * BeanPostProcessor, so RegisterBeanPostProcessorsPass discovers + installs it at phase 700. The proxy class is
 * an app artifact loaded by autoload (firefly:cache, M15) or inline in tests before this runs.
 */
#[Component]
final class TransactionalBeanPostProcessor implements BeanPostProcessor
{
    public function __construct(
        private readonly TransactionalManifest $manifest,
        private readonly ProxyFactory $factory,
        private readonly TransactionInterceptor $interceptor,
    ) {}

    public function beforeInitialization(object $bean, string $declaredClass): object
    {
        return $bean;
    }

    public function afterInitialization(object $bean, string $declaredClass): object
    {
        if (! $this->manifest->hasProxyFor($declaredClass)) {
            return $bean;
        }

        $proxyClass = $this->manifest->proxyClassFor($declaredClass);

        // Both classes are loaded by the time this runs: $bean IS-A $declaredClass, and the generated
        // $proxyClass is autoloaded by firefly:cache (or inline in tests) before the container resolves the
        // bean. class_exists() narrows the two plain strings to the class-string ProxyFactory::wrap() requires;
        // a manifest that promises a proxy whose class is not loaded is a real misconfiguration, so fail loud
        // rather than silently skip interception.
        if (! class_exists($declaredClass) || ! class_exists($proxyClass)) {
            throw new ConfigurationException("Transactional proxy [{$proxyClass}] for [{$declaredClass}] is not loaded. Run firefly:cache to generate it.");
        }

        return $this->factory->wrap($bean, $declaredClass, $proxyClass, $this->interceptor);
    }
}
