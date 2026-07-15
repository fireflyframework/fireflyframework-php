<?php

declare(strict_types=1);

namespace Firefly\Context\Pass;

use Firefly\Context\Boot\BootContext;
use Firefly\Context\Boot\BootPass;
use Firefly\Context\Boot\BootPhase;
use Firefly\Context\Event\AsEventListener;
use Firefly\Context\Event\DispatcherEventPublisher;
use Firefly\Kernel\Exception\Framework\ConfigurationException;
use Illuminate\Contracts\Events\Dispatcher;
use ReflectionClass;
use ReflectionMethod;
use ReflectionNamedType;

/**
 * Registers every #[AsEventListener] method against Illuminate's event dispatcher, in ONE
 * already-#[Order]-sorted sweep — Illuminate's dispatch loop fires listeners in REGISTRATION order,
 * so registering the complete, pre-sorted list in a single pass recovers #[Order] exactly; letting
 * listeners register themselves piecemeal (e.g. one pass per discovered component) would let a
 * later-discovered listener silently append at the tail and defeat #[Order] — the same reasoning
 * RegisterBeanPostProcessorsPass documents for its composite extenders.
 *
 * #[AsEventListener] methods are discovered by reflecting each component's DECLARED class listed in
 * the manifest — never a resolved instance. ComponentDescriptor carries no field for this attribute
 * (there is no compiled context scanner yet — that lands in a later milestone), so this pass reflects
 * declared classes directly, the same pattern InitDestroyInvoker already uses for
 * #[PostConstruct]/#[PreDestroy] discovery. The order sorted on is the attribute's OWN $order (the
 * #[Order] convention applied per listener method, since one class may declare several listener
 * methods needing independent ordering) — read purely from static method metadata, never from a
 * resolved bean.
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

        foreach ($context->definitions->all() as $definition) {
            $descriptor = $definition->descriptor;
            /** @var class-string $declaredClass */
            $declaredClass = $descriptor->class;
            $reflection = new ReflectionClass($declaredClass);

            foreach ($reflection->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
                foreach ($method->getAttributes(AsEventListener::class) as $attribute) {
                    /** @var AsEventListener $asEventListener */
                    $asEventListener = $attribute->newInstance();

                    $entries[] = [
                        $descriptor->class,
                        $method->getName(),
                        $asEventListener->event ?? $this->inferEventType($method, $descriptor->class),
                        $asEventListener->order,
                    ];
                }
            }
        }

        usort($entries, static fn (array $a, array $b): int => $a[3] <=> $b[3] ?: $a[0] <=> $b[0] ?: $a[1] <=> $b[1]);

        return $entries;
    }

    private function inferEventType(ReflectionMethod $method, string $declaringClass): string
    {
        $type = $method->getParameters()[0]->getType() ?? null;

        if ($type instanceof ReflectionNamedType && ! $type->isBuiltin()) {
            return $type->getName();
        }

        throw new ConfigurationException(
            "#[AsEventListener] on {$declaringClass}::{$method->getName()}() has no explicit event and its first ".
            'parameter has no inferable class type.',
        );
    }
}
