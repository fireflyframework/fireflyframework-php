<?php

declare(strict_types=1);

namespace Firefly\Webhooks\Idempotency;

use Closure;

/**
 * idempotency.store=memory: one process only (a test, a long-running worker). Under PHP-FPM every request starts
 * empty, which is why this is NOT the default.
 */
final class InMemoryWebhookIdempotencyStore implements WebhookIdempotencyStore
{
    /** @var array<string, int> claim id => expires at */
    private array $expiries = [];

    /** @var Closure(): int */
    private readonly Closure $clock;

    /**
     * @param  (Closure(): int)|null  $clock  unix seconds
     */
    public function __construct(?Closure $clock = null)
    {
        $this->clock = $clock ?? static fn (): int => time();
    }

    public function claim(string $provider, string $key, string $eventId, float $ttlSeconds): bool
    {
        $id = $provider."\0".$key;
        $now = ($this->clock)();

        if (isset($this->expiries[$id]) && $this->expiries[$id] > $now) {
            return false;
        }

        $this->expiries[$id] = $now + (int) ceil($ttlSeconds);

        return true;
    }

    public function release(string $provider, string $key): void
    {
        unset($this->expiries[$provider."\0".$key]);
    }
}
