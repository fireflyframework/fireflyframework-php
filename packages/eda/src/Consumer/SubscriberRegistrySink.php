<?php

declare(strict_types=1);

namespace Firefly\Eda\Consumer;

use Firefly\Eda\Bus\SubscriberRegistry;
use Firefly\Eda\EventEnvelope;

/**
 * The default EnvelopeSink: the #[EventListener] beans, via the SubscriberRegistry EventListenerWiringPass
 * populated at boot (its handlers already retry/DLQ-wrapped). This is exactly the callable `firefly:eda:consume`
 * used to build inline; it is a class so an application's own EnvelopeSink can be bound in its place.
 */
final class SubscriberRegistrySink implements EnvelopeSink
{
    public function __construct(private readonly SubscriberRegistry $registry) {}

    public function handle(EventEnvelope $envelope): void
    {
        $this->registry->deliver($envelope);
    }
}
