<?php

declare(strict_types=1);

namespace Firefly\Eda;

use DateTimeImmutable;

/**
 * The broker-bus envelope: an event-type + payload + routing/tracing headers, carried across process boundaries
 * by the eda adapters. Immutable; withHeaders() returns a copy. eventId is a uuid4 from random_bytes (no
 * ramsey/uuid, no reflection — same approach as firefly/domain's DomainEvent). toArray()/fromArray() serialise
 * the timestamp as an ISO-8601 string so the whole envelope is a plain array (what a JSON serializer emits).
 *
 * @phpstan-type EnvelopeRow array{eventType: string, destination: string, payload: array<string, mixed>, headers: array<string, string>, eventId: string, timestamp: string}
 */
final readonly class EventEnvelope
{
    /**
     * @param  array<string, mixed>  $payload
     * @param  array<string, string>  $headers
     */
    public function __construct(
        public string $eventType,
        public string $destination,
        public array $payload = [],
        public array $headers = [],
        ?string $eventId = null,
        ?DateTimeImmutable $timestamp = null,
    ) {
        $this->eventId = $eventId ?? self::uuid4();
        $this->timestamp = $timestamp ?? new DateTimeImmutable;
    }

    public string $eventId;

    public DateTimeImmutable $timestamp;

    /**
     * @param  array<string, string>  $extra
     */
    public function withHeaders(array $extra): self
    {
        return new self(
            $this->eventType,
            $this->destination,
            $this->payload,
            array_merge($this->headers, $extra),
            $this->eventId,
            $this->timestamp,
        );
    }

    /**
     * @return EnvelopeRow
     */
    public function toArray(): array
    {
        return [
            'eventType' => $this->eventType,
            'destination' => $this->destination,
            'payload' => $this->payload,
            'headers' => $this->headers,
            'eventId' => $this->eventId,
            'timestamp' => $this->timestamp->format(DATE_ATOM),
        ];
    }

    /**
     * @param  EnvelopeRow  $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            $data['eventType'],
            $data['destination'],
            $data['payload'],
            $data['headers'],
            $data['eventId'],
            new DateTimeImmutable($data['timestamp']),
        );
    }

    private static function uuid4(): string
    {
        $bytes = random_bytes(16);
        $bytes[6] = chr((ord($bytes[6]) & 0x0F) | 0x40);
        $bytes[8] = chr((ord($bytes[8]) & 0x3F) | 0x80);

        /** @var array<int, string> $chunks */
        $chunks = str_split(bin2hex($bytes), 4);

        return vsprintf('%s%s-%s-%s-%s-%s%s%s', $chunks);
    }
}
