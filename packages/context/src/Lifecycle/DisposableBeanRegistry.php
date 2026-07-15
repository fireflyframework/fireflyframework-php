<?php

declare(strict_types=1);

namespace Firefly\Context\Lifecycle;

use Firefly\Container\Scope;
use WeakReference;

/**
 * Tracks beans that need their #[PreDestroy] method(s) invoked, so a later boot pass can drain
 * them at the right time.
 *
 * Holds WEAK references, never strong ones: a *contextual* build (Illuminate's
 * needsContextualBuild — see KernelContractTest / the M4 design decisions doc) runs
 * BeanPostProcessor extenders but is NEVER cached by the container. A strong reference here
 * would pin every such throwaway instance in memory for the registry's lifetime — effectively
 * forever under Octane, since the registry itself lives for the whole worker process. A ref
 * whose get() returns null (already garbage-collected) is silently skipped on drain.
 *
 * Singleton and Scoped beans are tracked in SEPARATE ledgers because they drain at different
 * times: Singletons at context close; Scoped beans PER REQUEST. Scoped #[PreDestroy] callbacks
 * MUST run via drainScoped() BEFORE Illuminate\Container\Container::forgetScopedInstances(),
 * which has no destruction callback of its own and would otherwise silently drop anything not
 * drained first — a later Octane dispatch is responsible for calling drainScoped() in that
 * order. Scope::Transient beans are never tracked at all: prototype-scoped beans are not
 * lifecycle-managed (Spring parity).
 *
 * Only beans whose DECLARED class actually has a #[PreDestroy] method are tracked at all — this
 * is what makes the registry "Tracks beans needing #[PreDestroy]" rather than every bean.
 *
 * Both ledgers destroy in REVERSE registration order — dependents (registered later) are torn
 * down before the dependencies (registered earlier) they may still be using.
 */
final class DisposableBeanRegistry
{
    /** @var list<array{ref: WeakReference<object>, declaredClass: class-string}> */
    private array $singletons = [];

    /** @var list<array{ref: WeakReference<object>, declaredClass: class-string}> */
    private array $scoped = [];

    public function __construct(private readonly InitDestroyInvoker $invoker) {}

    /**
     * @param  class-string  $declaredClass
     */
    public function register(object $bean, string $declaredClass, Scope $scope): void
    {
        if (! $this->invoker->hasDestroyMethods($declaredClass)) {
            return;
        }

        $entry = ['ref' => WeakReference::create($bean), 'declaredClass' => $declaredClass];

        if ($scope === Scope::Singleton) {
            $this->singletons[] = $entry;
        } elseif ($scope === Scope::Scoped) {
            $this->scoped[] = $entry;
        }
        // Scope::Transient: prototype-scoped beans are not lifecycle-managed — never tracked.
    }

    /**
     * Singleton drain: called once, at context close.
     */
    public function drainSingletons(): void
    {
        $this->drain($this->singletons);
        $this->singletons = [];
    }

    /**
     * Scoped drain: called PER REQUEST, and MUST run before forgetScopedInstances() — see the
     * class docblock.
     */
    public function drainScoped(): void
    {
        $this->drain($this->scoped);
        $this->scoped = [];
    }

    /**
     * @param  list<array{ref: WeakReference<object>, declaredClass: class-string}>  $entries
     */
    private function drain(array $entries): void
    {
        foreach (array_reverse($entries) as $entry) {
            $bean = $entry['ref']->get();
            if ($bean === null) {
                continue;
            }

            $this->invoker->invokeDestroy($bean, $entry['declaredClass']);
        }
    }
}
