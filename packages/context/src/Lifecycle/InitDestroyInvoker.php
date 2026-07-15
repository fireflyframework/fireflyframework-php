<?php

declare(strict_types=1);

namespace Firefly\Context\Lifecycle;

use Firefly\Context\Scanner\ContextManifest;
use Illuminate\Container\Container;

/**
 * Invokes #[PostConstruct]/#[PreDestroy] methods on a bean.
 *
 * Method names come from the compiled ContextManifest, keyed by a class threaded in by the caller —
 * NEVER by reflecting a class at invocation time (see the class docblock of
 * Firefly\Context\Scanner\ContextScanner: reflection happens ONCE, at scan time; our baseline
 * runtime is PHP-FPM, which boots on every request, so "reflect here" would be per-request
 * reflection). ContextScanner itself only ever scans CONCRETE, instantiable classes — never an
 * interface or abstract class (see ContextScanner::describe()) — so every key this manifest
 * actually carries is a concrete class.
 *
 * INVARIANT 4, PRECISELY STATED: the key threaded in here must NEVER be recomputed from $bean::class
 * AT INVOCATION TIME — that is the part that must never be "simplified" back. The reason is proxy
 * identity, not the concrete/declared distinction itself: a BeanPostProcessor may replace $bean with
 * a wrapper/proxy in afterInitialization() (see BeanPostProcessorChain), and by the time
 * #[PreDestroy] runs (at context close or end of request) $bean may already BE such a wrapper —
 * reading $bean::class AT THAT POINT would inspect the wrapper's own runtime class, which carries no
 * manifest entry of its own, and silently skip every lifecycle callback. This does NOT mean the
 * caller must always pass the manifest's "declared" abstract, though: at BEAN INITIALIZATION time —
 * BEFORE any BeanPostProcessor has had the chance to wrap it (proxies are only ever created in
 * afterInitialization(), never earlier) — $bean is guaranteed to be the real, pre-proxy instance, so
 * $bean::class captured THERE is both safe and, for a #[Bean] method whose declared return type is
 * an INTERFACE, the ONLY correct key: the interface itself was never scanned, so the manifest has no
 * entry for it at all. See RegisterBeanPostProcessorsPass, which captures $bean::class once, at
 * exactly that pre-proxy moment, and threads it through as the lifecycle key for BOTH invokeInit()
 * here and DisposableBeanRegistry (for invokeDestroy() later, when the instance MAY by then be
 * proxied) — never recomputing it from the instance a second time.
 *
 * Invocation goes through $container->call([$bean, $method]) — NOT $bean->$method() — so
 * lifecycle method parameters get dependency injection, exactly like M2's #[Bean] factory
 * methods (see Firefly\Container\Registrar\ContainerRegistrar::registerBeans()).
 */
final class InitDestroyInvoker
{
    /** @var array<string, list<string>> #[PostConstruct] method names, keyed by declared class */
    private array $initMethods = [];

    /** @var array<string, list<string>> #[PreDestroy] method names, keyed by declared class */
    private array $destroyMethods = [];

    public function __construct(
        private readonly Container $container,
        ContextManifest $manifest = new ContextManifest([]),
    ) {
        foreach ($manifest->descriptors as $descriptor) {
            $this->initMethods[$descriptor->class] = $descriptor->postConstruct;
            $this->destroyMethods[$descriptor->class] = $descriptor->preDestroy;
        }
    }

    /**
     * @param  class-string  $declaredClass
     */
    public function invokeInit(object $bean, string $declaredClass): void
    {
        foreach ($this->initMethods[$declaredClass] ?? [] as $method) {
            $this->invoke($bean, $method);
        }
    }

    /**
     * #[PreDestroy] methods run in REVERSE order relative to how #[PostConstruct] methods on the
     * same declared class run (declaration order) — teardown mirrors startup, Spring-style.
     *
     * @param  class-string  $declaredClass
     */
    public function invokeDestroy(object $bean, string $declaredClass): void
    {
        foreach (array_reverse($this->destroyMethods[$declaredClass] ?? []) as $method) {
            $this->invoke($bean, $method);
        }
    }

    /**
     * @param  class-string  $declaredClass
     */
    public function hasDestroyMethods(string $declaredClass): bool
    {
        return ($this->destroyMethods[$declaredClass] ?? []) !== [];
    }

    private function invoke(object $bean, string $method): void
    {
        /** @var callable $callable */
        $callable = [$bean, $method];

        $this->container->call($callable);
    }
}
