<?php

declare(strict_types=1);

namespace Firefly\Context\Lifecycle;

use Illuminate\Container\Container;
use ReflectionClass;
use ReflectionMethod;

/**
 * Invokes #[PostConstruct]/#[PreDestroy] methods on a bean.
 *
 * Methods are discovered by reflecting the DECLARED class threaded in from the bean's
 * definition — NEVER $bean::class. A BeanPostProcessor may replace $bean with a wrapper/proxy in
 * afterInitialization() (see BeanPostProcessorChain), and by the time #[PreDestroy] runs (at
 * context close or end of request) $bean may already BE such a wrapper. Reflecting $bean::class
 * at that point would inspect the wrapper's own class — which carries none of the original
 * attributes — and silently skip every lifecycle callback. Callers MUST thread $declaredClass
 * through rather than recomputing it from the instance.
 *
 * Invocation goes through $container->call([$bean, $method]) — NOT $bean->$method() — so
 * lifecycle method parameters get dependency injection, exactly like M2's #[Bean] factory
 * methods (see Firefly\Container\Registrar\ContainerRegistrar::registerBeans()).
 */
final class InitDestroyInvoker
{
    /** @var array<string, list<string>> memoised #[PostConstruct] method names, keyed by declared class */
    private array $initMethodCache = [];

    /** @var array<string, list<string>> memoised #[PreDestroy] method names, keyed by declared class */
    private array $destroyMethodCache = [];

    public function __construct(private readonly Container $container) {}

    /**
     * @param  class-string  $declaredClass
     */
    public function invokeInit(object $bean, string $declaredClass): void
    {
        foreach ($this->initMethodsOf($declaredClass) as $method) {
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
        foreach (array_reverse($this->destroyMethodsOf($declaredClass)) as $method) {
            $this->invoke($bean, $method);
        }
    }

    /**
     * @param  class-string  $declaredClass
     */
    public function hasDestroyMethods(string $declaredClass): bool
    {
        return $this->destroyMethodsOf($declaredClass) !== [];
    }

    /**
     * @param  class-string  $declaredClass
     * @return list<string>
     */
    private function initMethodsOf(string $declaredClass): array
    {
        return $this->initMethodCache[$declaredClass] ??= $this->reflectMethods($declaredClass, PostConstruct::class);
    }

    /**
     * @param  class-string  $declaredClass
     * @return list<string>
     */
    private function destroyMethodsOf(string $declaredClass): array
    {
        return $this->destroyMethodCache[$declaredClass] ??= $this->reflectMethods($declaredClass, PreDestroy::class);
    }

    /**
     * @param  class-string  $declaredClass
     * @param  class-string  $attributeClass
     * @return list<string>
     */
    private function reflectMethods(string $declaredClass, string $attributeClass): array
    {
        $methods = [];

        foreach ((new ReflectionClass($declaredClass))->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
            if ($method->getAttributes($attributeClass) !== []) {
                $methods[] = $method->getName();
            }
        }

        return $methods;
    }

    private function invoke(object $bean, string $method): void
    {
        /** @var callable $callable */
        $callable = [$bean, $method];

        $this->container->call($callable);
    }
}
