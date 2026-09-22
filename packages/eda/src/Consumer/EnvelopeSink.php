<?php

declare(strict_types=1);

namespace Firefly\Eda\Consumer;

use Firefly\Eda\EventEnvelope;

/**
 * Where a consumed envelope goes. The port ConsumerLoop delivers to and `firefly:eda:consume` resolves.
 *
 * The default, SubscriberRegistrySink, delivers to the #[EventListener] beans — which is right for an
 * application whose listeners live in this process, and wrong for one whose events must go somewhere else: a
 * command bus, a projector, a relay to another service. That application used to write its own consumer
 * command, its own poll loop, its own signal handling, its own offset commits and its own dead-letter path,
 * because the only seam was a callable the command built and never let anyone replace. Bind an EnvelopeSink
 * and the command delivers to it; everything else stays the framework's.
 *
 * A throw from handle() is the signal "not handled": ConsumerLoop nacks with requeue and the broker
 * redelivers (at-least-once). A sink that has exhausted its own retries and wants the record gone should
 * dead-letter it itself and return normally, so the loop acks.
 */
interface EnvelopeSink
{
    public function handle(EventEnvelope $envelope): void;
}
