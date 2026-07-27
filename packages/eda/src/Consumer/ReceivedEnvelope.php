<?php

declare(strict_types=1);

namespace Firefly\Eda\Consumer;

use Firefly\Eda\EventEnvelope;

/** A polled message: the decoded envelope plus the broker-native handle needed to ack/nack it. */
final readonly class ReceivedEnvelope
{
    public function __construct(
        public EventEnvelope $envelope,
        public mixed $deliveryTag = null,
    ) {}
}
