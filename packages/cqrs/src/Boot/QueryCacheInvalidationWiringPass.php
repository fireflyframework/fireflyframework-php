<?php

declare(strict_types=1);

namespace Firefly\Cqrs\Boot;

use Firefly\Context\Boot\BootContext;
use Firefly\Context\Boot\BootPass;
use Firefly\Context\Boot\BootPhase;
use Firefly\Context\Event\DispatcherEventPublisher;
use Firefly\Cqrs\Cache\QueryCacheInvalidator;
use Firefly\Eda\EventEnvelope;
use Firefly\Eda\EventPublisher;
use Illuminate\Contracts\Events\Dispatcher;

/**
 * Connects the QueryCacheInvalidator (bound only when firefly.cqrs.query.cache.provider=cache) to both event paths,
 * at WiringPasses/50.
 *
 * In process: ONE guarded wildcard listener on the `events` dispatcher, for the reason DomainEventBridgeWiringPass
 * gives — Illuminate matches an object event by its concrete class and interfaces, never its parents, so a listener
 * per event class would miss subclasses. The data layer dispatches a DomainEvent only after its transaction commits
 * and discards it on rollback, so eviction follows the commit and a rolled-back write evicts nothing. EDA envelopes
 * are skipped here: they arrive through the subscription below.
 *
 * Over EDA: one EXACT subscription per event type the rules name — never a wildcard. A wildcard subscription would
 * make every process a consumer of everything (PyFly's EdaCacheInvalidationBridge lesson), only to discard it.
 */
final class QueryCacheInvalidationWiringPass implements BootPass
{
    public function phase(): BootPhase
    {
        return BootPhase::WiringPasses;
    }

    public function order(): int
    {
        return 50;
    }

    public function run(BootContext $context): void
    {
        $container = $context->container;
        if (! $container->bound(QueryCacheInvalidator::class)) {
            return;
        }

        /** @var QueryCacheInvalidator $invalidator */
        $invalidator = $container->make(QueryCacheInvalidator::class);

        if ($invalidator->hasClassRules()) {
            /** @var Dispatcher $dispatcher */
            $dispatcher = $container->make('events');
            $dispatcher->listen('*', DispatcherEventPublisher::guardListener(
                /**
                 * @param  array<int, mixed>  $payload
                 */
                static function (string $eventName, array $payload) use ($invalidator): void {
                    $event = $payload[0] ?? null;
                    if (is_object($event) && ! $event instanceof EventEnvelope) {
                        $invalidator->onEvent($event);
                    }
                },
            ));
        }

        if (! $container->bound(EventPublisher::class)) {
            return;
        }

        /** @var EventPublisher $publisher */
        $publisher = $container->make(EventPublisher::class);
        foreach ($invalidator->eventTypes() as $eventType) {
            $publisher->subscribe($eventType, static function (EventEnvelope $envelope) use ($invalidator): void {
                $invalidator->onEnvelope($envelope);
            });
        }
    }
}
