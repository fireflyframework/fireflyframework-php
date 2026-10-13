<?php

declare(strict_types=1);

namespace Firefly\Messaging\Container;

use Firefly\Messaging\Listener\MessageListenerDescriptor;

/**
 * What one #[MessageListener] says about its own failures, for a broker that runs a listener container (Kafka,
 * RabbitMQ). null means "the container's default". On a container `retries: 0` IS "the default" — an attribute
 * argument cannot tell unset from zero — while `retries: N > 0` means N + 1 attempts with retryDelay as a LINEAR
 * back-off, exactly as on the in-memory and queue brokers. A deadLetterTopic replaces the broker's default
 * dead-letter route for this listener.
 */
final readonly class ListenerOptions
{
    public function __construct(
        public ?int $maxAttempts = null,
        public ?BackOff $backOff = null,
        public ?string $deadLetterTopic = null,
    ) {}

    public static function fromDescriptor(MessageListenerDescriptor $descriptor): self
    {
        if ($descriptor->retries > 0) {
            return new self($descriptor->retries + 1, new LinearBackOff($descriptor->retryDelay), $descriptor->deadLetterTopic);
        }

        return new self(null, null, $descriptor->deadLetterTopic);
    }
}
