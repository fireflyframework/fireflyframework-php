<?php

declare(strict_types=1);

namespace Firefly\Context\Pass;

use Firefly\Container\Descriptor\ComponentDescriptor;
use Firefly\Context\Boot\BootContext;
use Firefly\Context\Boot\BootPass;
use Firefly\Context\Boot\BootPhase;
use Firefly\Context\Lifecycle\LifecycleRegistry;
use Firefly\Kernel\Lifecycle;
use Illuminate\Container\Container;

/**
 * Resolves and start()s every component implementing Firefly\Kernel\Lifecycle, sorted from the
 * MANIFEST by (order, class) — never from resolved instances (the same INVARIANT 3 rule
 * RegisterBeanPostProcessorsPass documents applies here: a proxied Lifecycle bean would silently
 * sort to order 0 if ordering were derived from getAll()/resolved instances instead).
 *
 * Runs AFTER BeanPostProcessors (700) deliberately: a Lifecycle bean resolved here must already have
 * its composite extender installed, or it would permanently escape post-processing (#[PostConstruct]
 * would never fire, #[PreDestroy] tracking would never happen — see the design decisions doc's DELTA
 * 1).
 *
 * FAIL-FAST: start() exceptions are never caught here. Infrastructure that cannot start (a dead DB
 * pool, an unreachable broker) must abort boot loudly — degrading silently is worse than crashing.
 *
 * Each started instance is tracked, in START order, in the shared LifecycleRegistry so
 * ApplicationContext::close() can stop() them in the mirrored REVERSE order.
 */
final class InfrastructureStartPass implements BootPass
{
    public function phase(): BootPhase
    {
        return BootPhase::InfrastructureStart;
    }

    public function order(): int
    {
        return 0;
    }

    public function run(BootContext $context): void
    {
        $container = $context->container;
        $registry = $this->lifecycleRegistry($container);

        foreach ($this->orderedLifecycleDescriptors($context) as $descriptor) {
            /** @var class-string $class */
            $class = $descriptor->class;

            /** @var Lifecycle $instance */
            $instance = $container->make($class);
            $instance->start();
            $registry->add($instance);
        }
    }

    /**
     * @return list<ComponentDescriptor>
     */
    private function orderedLifecycleDescriptors(BootContext $context): array
    {
        $descriptors = array_values(array_filter(
            array_map(
                static fn ($definition): ComponentDescriptor => $definition->descriptor,
                $context->definitions->all(),
            ),
            static fn (ComponentDescriptor $d): bool => in_array(Lifecycle::class, $d->interfaces, true),
        ));

        usort($descriptors, static fn (ComponentDescriptor $a, ComponentDescriptor $b): int => $a->order <=> $b->order ?: $a->class <=> $b->class);

        return $descriptors;
    }

    private function lifecycleRegistry(Container $container): LifecycleRegistry
    {
        if (! $container->bound(LifecycleRegistry::class)) {
            $container->instance(LifecycleRegistry::class, new LifecycleRegistry);
        }

        /** @var LifecycleRegistry $registry */
        $registry = $container->make(LifecycleRegistry::class);

        return $registry;
    }
}
