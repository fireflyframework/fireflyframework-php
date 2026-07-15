<?php

declare(strict_types=1);

namespace Firefly\Context\Pass;

use Firefly\Container\Descriptor\ComponentDescriptor;
use Firefly\Container\Scope;
use Firefly\Context\Boot\BootContext;
use Firefly\Context\Boot\BootPass;
use Firefly\Context\Boot\BootPhase;
use Firefly\Context\Lifecycle\DisposableBeanRegistry;
use Firefly\Context\Lifecycle\InitDestroyInvoker;
use Firefly\Context\Processor\BeanPostProcessor;
use Firefly\Context\Processor\BeanPostProcessorChain;
use Illuminate\Container\Container;

/**
 * Installs exactly ONE composite Illuminate extender per abstract (INVARIANT 2), delegating to a
 * single #[Order]-frozen BeanPostProcessorChain (INVARIANT 1: extend() is the only BeanPostProcessor
 * seam — see KernelContractTest). Illuminate's extenders fire in REGISTRATION order, so one extender
 * per BPP would let a later-discovered BPP silently append at the tail and defeat #[Order]; a single
 * composite extender per abstract keeps the frozen chain the only thing deciding order.
 *
 * BPPs are discovered AND ordered from the MANIFEST — never from a resolved instance (INVARIANT 3):
 * Firefly\Container\Container::orderOf() keys on $instance::class, so a proxied BPP would silently
 * sort to order 0. The ordered list is frozen, from ComponentDescriptor::$order/$class, BEFORE any
 * BPP is resolved — resolving a BPP class through the container CAN legitimately return a proxy
 * (e.g. if something already registered an extend() for that class), and the sort above must not
 * care either way.
 *
 * $declaredClass threaded into every extender is the MANIFEST's declared class (component class or
 * #[Bean] return type) — never $o::class (INVARIANT 4: a proxy's runtime class has no manifest
 * entry).
 *
 * The same extender ALSO registers the processed bean into the shared DisposableBeanRegistry (for
 * #[PreDestroy] at context close, see ApplicationContext::close()) — this is the ONE seam every bean
 * (component or #[Bean] factory output, eager or lazily resolved later during a request) passes
 * through, so it is the correct place to observe "a bean was just built" for lifecycle bookkeeping.
 *
 * BPP classes never post-process themselves into existence: they are resolved BEFORE any composite
 * extender is installed (so none of them can run through a chain, including their own, that does not
 * exist yet), and are explicitly excluded from the set of abstracts that get an extender at all.
 */
final class RegisterBeanPostProcessorsPass implements BootPass
{
    public function phase(): BootPhase
    {
        return BootPhase::BeanPostProcessors;
    }

    public function order(): int
    {
        return 0;
    }

    public function run(BootContext $context): void
    {
        $container = $context->container;
        $invoker = new InitDestroyInvoker($container, $context->contextManifest);
        $disposables = $this->disposableBeanRegistry($container, $invoker);

        /** @var list<ComponentDescriptor> $descriptors */
        $descriptors = array_map(
            static fn ($definition): ComponentDescriptor => $definition->descriptor,
            $context->definitions->all(),
        );

        $bppClasses = $this->orderedBeanPostProcessorClasses($descriptors);

        /** @var list<BeanPostProcessor> $ordered */
        $ordered = array_map(
            static function (string $class) use ($container): BeanPostProcessor {
                /** @var BeanPostProcessor $instance */
                $instance = $container->make($class);

                return $instance;
            },
            $bppClasses,
        );

        $chain = new BeanPostProcessorChain($ordered, $invoker);

        $bppClassSet = array_fill_keys($bppClasses, true);

        foreach ($this->abstractsToExtend($descriptors, $bppClassSet) as $abstract => $target) {
            [$declaredClass, $scope] = $target;

            $container->extend($abstract, static function (object $bean) use ($chain, $declaredClass, $scope, $disposables): object {
                $processed = $chain->process($bean, $declaredClass);
                $disposables->register($processed, $declaredClass, $scope);

                return $processed;
            });
        }
    }

    private function disposableBeanRegistry(Container $container, InitDestroyInvoker $invoker): DisposableBeanRegistry
    {
        if (! $container->bound(DisposableBeanRegistry::class)) {
            $container->instance(DisposableBeanRegistry::class, new DisposableBeanRegistry($invoker));
        }

        /** @var DisposableBeanRegistry $registry */
        $registry = $container->make(DisposableBeanRegistry::class);

        return $registry;
    }

    /**
     * @param  list<ComponentDescriptor>  $descriptors
     * @return list<class-string>
     */
    private function orderedBeanPostProcessorClasses(array $descriptors): array
    {
        $bpps = array_values(array_filter(
            $descriptors,
            static fn (ComponentDescriptor $d): bool => in_array(BeanPostProcessor::class, $d->interfaces, true),
        ));

        usort($bpps, static fn (ComponentDescriptor $a, ComponentDescriptor $b): int => $a->order <=> $b->order ?: $a->class <=> $b->class);

        /** @var list<class-string> */
        return array_map(static fn (ComponentDescriptor $d): string => $d->class, $bpps);
    }

    /**
     * @param  list<ComponentDescriptor>  $descriptors
     * @param  array<string, true>  $bppClassSet
     * @return array<string, array{0: class-string, 1: Scope}>
     */
    private function abstractsToExtend(array $descriptors, array $bppClassSet): array
    {
        /** @var array<string, array{0: class-string, 1: Scope}> $abstracts */
        $abstracts = [];

        foreach ($descriptors as $descriptor) {
            if (! isset($bppClassSet[$descriptor->class])) {
                /** @var class-string $class */
                $class = $descriptor->class;
                $abstracts[$class] = [$class, $descriptor->scope];
            }

            foreach ($descriptor->beans as $bean) {
                if ($bean->returns !== '' && ! isset($bppClassSet[$bean->returns])) {
                    /** @var class-string $returns */
                    $returns = $bean->returns;
                    $abstracts[$returns] = [$returns, $bean->scope];
                }
            }
        }

        return $abstracts;
    }
}
