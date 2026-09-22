<?php

declare(strict_types=1);

namespace Firefly\Eda\Consumer;

use Firefly\Eda\EventEnvelope;
use Throwable;

/**
 * A polled message: the decoded envelope plus the broker-native handle needed to ack/nack it — or, when the
 * bytes could not be decoded, a POISON record: the raw bytes, the failure, and the destination they came from.
 *
 * WHY A RECORD RATHER THAN A THROW. Every adapter used to deserialise inside poll(), and poll() sat outside
 * ConsumerLoop's try/catch, so the most ordinary failure on a cross-language topic — a producer in another
 * language writing a shape this serializer does not know — was not a dead letter but a crash, and the
 * supervisor restarted the worker onto the same record for ever. An adapter that cannot decode a record now
 * says so IN the record: `envelope` is null, `raw` holds the bytes verbatim (so a fixed producer can be
 * replayed byte for byte), `failure` says why, and `destination` names the topic or queue so the dead-letter
 * route can be derived without an envelope. ConsumerLoop nacks a poison record without requeue — the broker's
 * dead-letter path — and keeps polling.
 */
final readonly class ReceivedEnvelope
{
    public function __construct(
        public ?EventEnvelope $envelope,
        public mixed $deliveryTag = null,
        public ?string $raw = null,
        public ?Throwable $failure = null,
        public ?string $destination = null,
    ) {}

    /** A record whose bytes could not be decoded into an envelope. */
    public static function poison(string $raw, mixed $deliveryTag, Throwable $failure, ?string $destination = null): self
    {
        return new self(null, $deliveryTag, $raw, $failure, $destination);
    }

    public function isPoison(): bool
    {
        return $this->envelope === null;
    }
}
