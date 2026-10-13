<?php

declare(strict_types=1);

namespace Firefly\Webhooks;

/** What the endpoint answers: 202 for an accepted (published) webhook, 200 for a delivery already accepted. */
final readonly class WebhookResult
{
    public function __construct(
        public string $status,
        public string $provider,
        public ?string $eventId = null,
        public ?string $eventType = null,
        public ?string $destination = null,
        public ?string $idempotencyKey = null,
    ) {}

    public static function accepted(string $eventId, string $provider, string $eventType, string $destination, ?string $idempotencyKey): self
    {
        return new self('accepted', $provider, $eventId, $eventType, $destination, $idempotencyKey);
    }

    public static function duplicate(string $provider, string $idempotencyKey): self
    {
        return new self('duplicate', $provider, idempotencyKey: $idempotencyKey);
    }

    public function httpStatus(): int
    {
        return $this->status === 'accepted' ? 202 : 200;
    }

    /**
     * @return array<string, string|null>
     */
    public function toArray(): array
    {
        return array_filter([
            'status' => $this->status,
            'id' => $this->eventId,
            'provider' => $this->provider,
            'eventType' => $this->eventType,
            'destination' => $this->destination,
            'idempotencyKey' => $this->idempotencyKey,
        ], static fn (?string $value): bool => $value !== null);
    }
}
