<?php

declare(strict_types=1);

namespace Firefly\Webhooks\Idempotency;

/**
 * Remembers which deliveries were already accepted, so a provider's retry of a webhook we processed is answered
 * "duplicate" instead of being published twice. claim() is ATOMIC — check-and-remember in one step — because a
 * provider that retries after a timeout can deliver the same webhook twice at the same moment, and PyFly's
 * `already_processed()` then `remember()` lets both through. release() undoes a claim whose publish failed, so the
 * provider's next retry is processed rather than dropped as a duplicate of something nobody received.
 */
interface WebhookIdempotencyStore
{
    /** true when this call is the first to claim ($provider, $key) within the TTL. */
    public function claim(string $provider, string $key, string $eventId, float $ttlSeconds): bool;

    public function release(string $provider, string $key): void;
}
