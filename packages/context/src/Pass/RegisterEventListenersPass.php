<?php

declare(strict_types=1);

namespace Firefly\Context\Pass;

use Firefly\Context\Boot\BootContext;
use Firefly\Context\Boot\BootPass;
use Firefly\Context\Boot\BootPhase;
use Firefly\Context\Event\DispatcherEventPublisher;
use Illuminate\Contracts\Events\Dispatcher;

/**
 * Registers every #[AsEventListener] method against Illuminate's event dispatcher, in ONE
 * already-#[Order]-sorted sweep — Illuminate's dispatch loop fires listeners in REGISTRATION order,
 * so registering the complete, pre-sorted list in a single pass recovers #[Order] exactly; letting
 * listeners register themselves piecemeal (e.g. one pass per discovered component) would let a
 * later-discovered listener silently append at the tail and defeat #[Order] — the same reasoning
 * RegisterBeanPostProcessorsPass documents for its composite extenders.
 *
 * #[AsEventListener] methods are read from the compiled ContextManifest (BootContext::$contextManifest)
 * — never by reflecting a component's declared class at boot. ContextScanner already resolved each
 * listener's $event (inferring it from the listener method's first parameter type ONCE, at scan
 * time, if it was left null) and its $order, so this pass does nothing but look the descriptor up
 * per definition and register what it finds — the same zero-reflection-at-load contract every other
 * Firefly manifest keeps. The order sorted on is the attribute's OWN $order (the #[Order] convention
 * applied per listener method, since one class may declare several listener methods needing
 * independent ordering), read purely from the manifest, never from a resolved bean.
 *
 * 🔴 THE BLOCKING REQUIREMENT this pass exists to satisfy: every raw listener is routed through
 * DispatcherEventPublisher::guardListener(). Illuminate\Events\Dispatcher::invokeListeners() breaks
 * UNCONDITIONALLY the instant any listener returns exactly `false` — regardless of the $halt flag —
 * so an unguarded listener whose last expression happens to be falsy (trivially easy by accident,
 * e.g. `return $repository->delete($id);`) would silently starve every listener registered after it.
 * Skipping this wrapping ships that bug live in production while every unit test of guardListener()
 * in isolation still passes green — see RegisterEventListenersPassTest's end-to-end proof.
 *
 * The listener closure resolves its target bean via the container on EVERY dispatch (never caching
 * it at registration time) — mirroring DispatcherEventPublisher's own per-call dispatcher
 * resolution — so it always observes the bean's current, fully post-processed (possibly proxied)
 * form.
 *
 * Listeners are looked up not only for each definition's OWN class, but for every non-empty
 * `#[Bean]` method return type too (M4 review #5, Minor 5 — a real gap, not merely a documented
 * limitation): `ContextScanner::describe()` captures `#[AsEventListener]` for ANY concrete class in
 * a scanned root, including one that is never itself a `#[Component]` but is instead PRODUCED by a
 * `#[Bean]` factory method (`#[Configuration] class C { #[Bean] fn(): RedisCache {...} }`) — for
 * that shape, the manifest holds an entry keyed `forClass('App\RedisCache')`, never `forClass('C')`.
 * `EagerSingletonsPass` and `RegisterBeanPostProcessorsPass` both already iterate
 * `$descriptor->beans`/`$bean->returns` for exactly this reason (a `#[Bean]` output is a
 * first-class lifecycle-managed thing, not merely its declaring class); this pass now does the
 * same, so `#[PostConstruct]`/`#[PreDestroy]`/`#[AsEventListener]` are symmetric across every
 * `#[Bean]` output. `$bean->returns` is resolved via `$container->make($bean->returns)` — the same
 * abstract `ContainerRegistrar::registerBeans()` bound the factory under — so the listener always
 * observes the fully post-processed (possibly proxied) bean, exactly like a plain `#[Component]`.
 * A class already visited (as either a definition's own class OR an earlier bean's return type) is
 * never visited twice, so a class reachable both ways cannot register the same listener method
 * twice.
 */
final class RegisterEventListenersPass implements BootPass
{
    public function phase(): BootPhase
    {
        return BootPhase::EventListeners;
    }

    public function order(): int
    {
        return 0;
    }

    public function run(BootContext $context): void
    {
        $container = $context->container;

        /** @var Dispatcher $dispatcher */
        $dispatcher = $container->make('events');

        foreach ($this->orderedListeners($context) as [$class, $method, $event]) {
            $raw = static function (mixed ...$arguments) use ($container, $class, $method): mixed {
                /** @var object $bean */
                $bean = $container->make($class);

                return $bean->{$method}(...$arguments);
            };

            $dispatcher->listen($event, DispatcherEventPublisher::guardListener($raw));
        }
    }

    /**
     * @return list<array{0: string, 1: string, 2: string, 3: int}>
     */
    private function orderedListeners(BootContext $context): array
    {
        $entries = [];

        /** @var array<string, true> $visited guards against visiting the same class twice */
        $visited = [];

        foreach ($context->definitions->all() as $definition) {
            $this->collectListenersFor($context, $definition->class(), $visited, $entries);

            foreach ($definition->descriptor->beans as $bean) {
                if ($bean->returns !== '') {
                    $this->collectListenersFor($context, $bean->returns, $visited, $entries);
                }
            }
        }

        usort($entries, static fn (array $a, array $b): int => $a[3] <=> $b[3] ?: $a[0] <=> $b[0] ?: $a[1] <=> $b[1]);

        return $entries;
    }

    /**
     * @param  array<string, true>  $visited
     * @param  list<array{0: string, 1: string, 2: string, 3: int}>  $entries
     */
    private function collectListenersFor(BootContext $context, string $class, array &$visited, array &$entries): void
    {
        if (isset($visited[$class])) {
            return;
        }
        $visited[$class] = true;

        $descriptor = $context->contextManifest->forClass($class);
        if ($descriptor === null) {
            return;
        }

        foreach ($descriptor->listeners as $listener) {
            $entries[] = [$class, $listener['method'], $listener['event'], $listener['order']];
        }
    }
}
