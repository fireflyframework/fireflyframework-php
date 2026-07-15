<?php

declare(strict_types=1);

namespace Firefly\Context\Lifecycle;

use Firefly\Context\Scanner\ContextManifest;
use Illuminate\Container\Container;

/**
 * Invokes #[PostConstruct]/#[PreDestroy] methods on a bean.
 *
 * Method names come from the compiled ContextManifest, keyed by the DECLARED class threaded in
 * from the bean's definition — NEVER $bean::class — and NEVER by reflecting that class at
 * invocation time (see the class docblock of Firefly\Context\Scanner\ContextScanner: reflection
 * happens ONCE, at scan time; our baseline runtime is PHP-FPM, which boots on every request, so
 * "reflect here" would be per-request reflection). A BeanPostProcessor may replace $bean with a
 * wrapper/proxy in afterInitialization() (see BeanPostProcessorChain), and by the time
 * #[PreDestroy] runs (at context close or end of request) $bean may already BE such a wrapper.
 * Looking up $bean::class in the manifest at that point would inspect the wrapper's own class —
 * which carries no manifest entry of its own — and silently skip every lifecycle callback.
 * Callers MUST thread $declaredClass through rather than recomputing it from the instance.
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
