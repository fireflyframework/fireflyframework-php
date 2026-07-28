<?php

declare(strict_types=1);

namespace Firefly\Eda\Bus;

use Firefly\Eda\EventEnvelope;
use Firefly\Eda\EventPublisher;
use Firefly\Kernel\Exception\Infrastructure\InfrastructureException;
use Illuminate\Bus\Queueable;
use Illuminate\Container\Container;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

/**
 * The async delivery unit: QueueEventBus::publish() dispatches ONE of these carrying the EventEnvelope, and the
 * queue worker runs handle() on its OWN process. handle() resolves the process-local EventPublisher singleton —
 * whose SubscriberRegistry was populated during THIS worker's boot by EventListenerWiringPass from the same
 * compiled manifest — and calls deliver(), so the share-nothing worker match+invokes the identical listener set
 * (see the async-delivery model note). The envelope is a flat readonly VO (scalars + arrays + a DateTimeImmutable),
 * so Laravel's default job serialization carries it across the queue with no custom logic.
 */
final class DispatchEventJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    public function __construct(public readonly EventEnvelope $envelope) {}

    public function handle(Container $container): void
    {
        $publisher = $this->resolvePublisher($container);

        if (! $publisher instanceof QueueEventBus) {
            throw new InfrastructureException(
                'DispatchEventJob requires the queue EventPublisher (QueueEventBus); got '.$publisher::class.'. '
                .'Set firefly.eda.provider=queue so the async adapter is bound on the worker.',
                'EDA_QUEUE_MISCONFIGURED',
            );
        }

        $publisher->deliver($this->envelope);
    }

    /**
     * Resolves the bound EventPublisher as its CONTRACT type. Routing the resolution through this interface-typed
     * boundary keeps the misconfiguration guard above honest under static analysis: an analyzer that resolves the
     * container's default (in-memory) binding would otherwise narrow make(EventPublisher::class) to the concrete
     * InMemoryEventBus and flag the `instanceof QueueEventBus` check — which only holds when firefly.eda.provider=queue
     * — as statically dead. Runtime behaviour is identical to calling make() inline.
     */
    private function resolvePublisher(Container $container): EventPublisher
    {
        return $container->make(EventPublisher::class);
    }
}
