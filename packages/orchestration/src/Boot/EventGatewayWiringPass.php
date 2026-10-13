<?php

declare(strict_types=1);

namespace Firefly\Orchestration\Boot;

use Firefly\Context\Boot\BootContext;
use Firefly\Context\Boot\BootPass;
use Firefly\Context\Boot\BootPhase;
use Firefly\Eda\EventEnvelope;
use Firefly\Eda\EventPublisher;
use Firefly\Orchestration\Event\EventGateway;

/**
 * Subscribes every trigger event type the gateway knows on the application's EventPublisher (WiringPasses,
 * order 30 — after the registry pass filled the gateway). It runs in every process, web and worker alike, so a
 * queue-driven EDA transport delivers triggers to whichever process consumes the event (the
 * EventListenerWiringPass rule). A delivered envelope's payload is the execution input; its headers, plus the
 * envelope's `eventId` and `eventType`, are the execution headers (what #[Header] and #[Headers] inject); and its
 * correlation id is EventGateway::correlationIdFor() the event, so a redelivery is acknowledged, not re-run.
 */
final class EventGatewayWiringPass implements BootPass
{
    public function phase(): BootPhase
    {
        return BootPhase::WiringPasses;
    }

    public function order(): int
    {
        return 30;
    }

    public function run(BootContext $context): void
    {
        $container = $context->container;
        if (! $container->bound(EventGateway::class) || ! $container->bound(EventPublisher::class)) {
            return;
        }

        /** @var EventGateway $gateway */
        $gateway = $container->make(EventGateway::class);
        if ($gateway->count() === 0) {
            return;
        }

        /** @var EventPublisher $publisher */
        $publisher = $container->make(EventPublisher::class);
        foreach ($gateway->eventTypes() as $eventType) {
            $publisher->subscribe($eventType, static function (EventEnvelope $envelope) use ($gateway, $eventType): void {
                $payload = [];
                foreach ($envelope->payload as $key => $value) {
                    $payload[(string) $key] = $value;
                }
                $headers = $envelope->headers;
                $headers['eventId'] = $envelope->eventId;
                $headers['eventType'] = $envelope->eventType;
                $gateway->route($eventType, $payload, $headers, EventGateway::correlationIdFor($envelope->eventType, $envelope->eventId));
            });
        }
    }
}
