<?php

declare(strict_types=1);

namespace Firefly\Webhooks\Idempotency;

use Illuminate\Contracts\Cache\Repository;

/**
 * idempotency.store=cache (the default): Repository::add() with a TTL — atomic on redis, memcached, the database
 * and the array stores, which is the property claim() promises. Always called with a TTL of at least one second:
 * Laravel's add() without one falls back to a non-atomic get-then-put. Keys are hashed so any delivery id fits
 * every store's key limit.
 */
final class CacheWebhookIdempotencyStore implements WebhookIdempotencyStore
{
    public function __construct(
        private readonly Repository $cache,
        private readonly string $prefix = 'firefly:webhooks:receipt:',
    ) {}

    public function claim(string $provider, string $key, string $eventId, float $ttlSeconds): bool
    {
        return $this->cache->add($this->key($provider, $key), $eventId, max(1, (int) ceil($ttlSeconds)));
    }

    public function release(string $provider, string $key): void
    {
        $this->cache->forget($this->key($provider, $key));
    }

    private function key(string $provider, string $key): string
    {
        return $this->prefix.hash('sha256', $provider."\0".$key);
    }
}
