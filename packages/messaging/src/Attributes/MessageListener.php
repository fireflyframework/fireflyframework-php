<?php

declare(strict_types=1);

namespace Firefly\Messaging\Attributes;

use Attribute;

/**
 * Marks a public bean method as a raw-bytes message consumer for a topic — the broker-agnostic ONE attribute for
 * ALL brokers (pyfly messaging/decorators.py; the @KafkaListener/@RabbitListener equivalent, NO broker-specific
 * variant — locked D2). Carries the consumer $group (competing-consumers within a group) and this listener's own
 * retry policy: $retries (linear-backoff retries on throw), $retryDelay (seconds, multiplied by the attempt), and
 * $deadLetterTopic (where an exhausted message is re-published; null = propagate). INERT METADATA ONLY —
 * discovery + subscription live in MessageListenerScanner (the sole reflection site) → MessageListenerManifest →
 * MessageListenerWiringPass.
 */
#[Attribute(Attribute::TARGET_METHOD)]
final class MessageListener
{
    public function __construct(
        public string $topic,
        public ?string $group = null,
        public int $retries = 0,
        public float $retryDelay = 0.0,
        public ?string $deadLetterTopic = null,
    ) {}
}
