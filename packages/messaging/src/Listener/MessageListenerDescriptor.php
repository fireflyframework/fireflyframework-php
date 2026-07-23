<?php

declare(strict_types=1);

namespace Firefly\Messaging\Listener;

/**
 * A single compiled messaging consumer: the target class/method plus its topic, consumer group, and per-listener
 * retry/DLQ policy. Every field is scalar-or-null so the manifest var_exports as a plain array literal, loaded by
 * require+map in production. Mirrors EventListenerDescriptor.
 *
 * @phpstan-type MessageListenerRow array{class: string, method: string, topic: string, group: string|null, retries: int, retryDelay: float, deadLetterTopic: string|null}
 */
final readonly class MessageListenerDescriptor
{
    public function __construct(
        public string $class,
        public string $method,
        public string $topic,
        public ?string $group = null,
        public int $retries = 0,
        public float $retryDelay = 0.0,
        public ?string $deadLetterTopic = null,
    ) {}

    /**
     * @return MessageListenerRow
     */
    public function toArray(): array
    {
        return [
            'class' => $this->class,
            'method' => $this->method,
            'topic' => $this->topic,
            'group' => $this->group,
            'retries' => $this->retries,
            'retryDelay' => $this->retryDelay,
            'deadLetterTopic' => $this->deadLetterTopic,
        ];
    }

    /**
     * @param  MessageListenerRow  $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            $data['class'],
            $data['method'],
            $data['topic'],
            $data['group'],
            $data['retries'],
            $data['retryDelay'],
            $data['deadLetterTopic'],
        );
    }
}
