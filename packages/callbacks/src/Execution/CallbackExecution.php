<?php

declare(strict_types=1);

namespace Firefly\Callbacks\Execution;

use Firefly\Callbacks\Subscription\CallbackSubscription;
use Firefly\Eda\EventEnvelope;

/**
 * One event delivered (or not) to one subscription. The id is sha256(subscription id, event id): an envelope the
 * broker redelivers maps to the SAME execution, so the dispatcher can tell it was already delivered and not send it
 * twice. The body is fixed when the execution is created — the exact bytes every attempt and every replay sends,
 * re-signed with a fresh timestamp each time:
 *
 *     {"id": <execution id>, "eventId": …, "eventType": …, "occurredAt": <ISO-8601>, "data": <the event payload>}
 *
 * Times are unix seconds, like every other firefly table. lastError is cut to MAX_ERROR_LENGTH bytes on a UTF-8
 * character boundary, so a long transport error or response excerpt always fits the column.
 */
final readonly class CallbackExecution
{
    public const int MAX_ERROR_LENGTH = 2000;

    public function __construct(
        public string $id,
        public string $subscriptionId,
        public string $eventId,
        public string $eventType,
        public string $url,
        public string $body,
        public CallbackStatus $status = CallbackStatus::Pending,
        public int $attempts = 0,
        public ?int $responseStatus = null,
        public ?string $lastError = null,
        public int $createdAt = 0,
        public int $updatedAt = 0,
    ) {}

    /** The deterministic execution id: 64 hex characters, the NUL separator keeping ("a", "bc") and ("ab", "c") apart. */
    public static function idFor(string $subscriptionId, string $eventId): string
    {
        return hash('sha256', $subscriptionId."\0".$eventId);
    }

    public static function pending(CallbackSubscription $subscription, EventEnvelope $envelope, int $now): self
    {
        $id = self::idFor($subscription->id, $envelope->eventId);
        $body = json_encode([
            'id' => $id,
            'eventId' => $envelope->eventId,
            'eventType' => $envelope->eventType,
            'occurredAt' => $envelope->timestamp->format(DATE_ATOM),
            'data' => $envelope->payload,
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION);

        return new self($id, $subscription->id, $envelope->eventId, $envelope->eventType, $subscription->url, $body, CallbackStatus::Pending, 0, null, null, $now, $now);
    }

    public function withOutcome(CallbackStatus $status, int $attempts, ?int $responseStatus, ?string $lastError, int $now): self
    {
        return new self(
            $this->id,
            $this->subscriptionId,
            $this->eventId,
            $this->eventType,
            $this->url,
            $this->body,
            $status,
            $attempts,
            $responseStatus,
            $lastError === null ? null : mb_strcut($lastError, 0, self::MAX_ERROR_LENGTH, 'UTF-8'),
            $this->createdAt,
            $now,
        );
    }
}
