<?php

declare(strict_types=1);

namespace Firefly\Cqrs\Boot;

use Firefly\Context\Boot\BootContext;
use Firefly\Context\Boot\BootPass;
use Firefly\Context\Boot\BootPhase;
use Firefly\Context\Event\DispatcherEventPublisher;
use Firefly\Cqrs\Event\DomainEventBridge;
use Firefly\Domain\DomainEvent;
use Illuminate\Contracts\Events\Dispatcher;

/**
 * Registers the domain->integration bridge trigger at phase WiringPasses/1000. The design's literal #[AsEventListener]
 * on DomainEvent is UNIMPLEMENTABLE: Illuminate's dispatcher matches an object event by its concrete class name plus
 * class_implements() (interfaces) — it never walks class_parents() (see packages/context/src/Event/ApplicationReadyEvent
 * .php:14-15), and DomainEvent is an abstract base implementing no interface, so a base-class listener never fires for
 * a concrete subclass. Instead we register ONE guarded wildcard listener on the 'events' dispatcher: it filters
 * instanceof DomainEvent and delegates to the freshly-resolved DomainEventBridge. Routed through
 * DispatcherEventPublisher::guardListener so a falsy return can never break Illuminate's dispatch loop for other
 * events. This fires for EVERY committed in-process DomainEvent M8 publishes after commit (any subclass), and nothing
 * on rollback (M8 discards the afterCommit callbacks). Reflection-free; couples only to Domain + the Context publish
 * seam, never to Data (no Cqrs -> Data edge).
 */
final class DomainEventBridgeWiringPass implements BootPass
{
    public function phase(): BootPhase
    {
        return BootPhase::WiringPasses;
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

        $dispatcher->listen('*', DispatcherEventPublisher::guardListener(
            /**
             * @param  array<int, mixed>  $payload
             */
            static function (string $eventName, array $payload) use ($container): void {
                $event = $payload[0] ?? null;
                if ($event instanceof DomainEvent) {
                    $container->make(DomainEventBridge::class)->publish($event);
                }
            },
        ));
    }
}
