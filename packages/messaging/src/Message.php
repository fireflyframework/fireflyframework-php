<?php

declare(strict_types=1);

namespace Firefly\Messaging;

/**
 * A raw-bytes broker message: a topic, an opaque byte-string value, an optional partition/routing key, and string
 * headers. Lower-level than eda's EventEnvelope — no event-type pattern, no serializer, no uuid. Immutable;
 * withHeaders() returns a copy. (pyfly messaging/types.py Message parity.)
 */
final readonly class Message
{
    /**
     * @param  array<string, string>  $headers
     */
    public function __construct(
        public string $topic,
        public string $value,
        public ?string $key = null,
        public array $headers = [],
    ) {}

    /**
     * @param  array<string, string>  $extra
     */
    public function withHeaders(array $extra): self
    {
        return new self($this->topic, $this->value, $this->key, array_merge($this->headers, $extra));
    }
}
