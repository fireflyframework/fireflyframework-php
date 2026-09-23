<?php

declare(strict_types=1);

use Firefly\Config\Config;
use Firefly\Resilience\ResilienceRegistry;
use Firefly\Resilience\Store\ResilienceStore;
use Illuminate\Config\Repository;

/**
 * A store that records the TTL every write was given — the only thing this test is about.
 *
 * The recording array is taken BY REFERENCE and bound to the property in the constructor body rather than
 * promoted: a by-reference promoted property is 8.4 territory, and this suite parses at the framework's 8.3
 * floor.
 *
 * @param  array<string, float|null>  $ttls
 */
function recordingStore(array &$ttls): ResilienceStore
{
    return new class($ttls) implements ResilienceStore
    {
        /** @var array<string, mixed> */
        private array $values = [];

        /** @var array<string, float|null> */
        public array $ttls;

        /** @param  array<string, float|null>  $ttls */
        public function __construct(array &$ttls)
        {
            $this->ttls = &$ttls;
        }

        public function get(string $key): mixed
        {
            return $this->values[$key] ?? null;
        }

        public function put(string $key, mixed $value, ?float $ttlSeconds = null): void
        {
            $this->values[$key] = $value;
            $this->ttls[$key] = $ttlSeconds;
        }

        public function add(string $key, mixed $value, ?float $ttlSeconds = null): bool
        {
            if (array_key_exists($key, $this->values)) {
                return false;
            }
            $this->put($key, $value, $ttlSeconds);

            return true;
        }

        public function increment(string $key, int $by = 1): int
        {
            $current = is_int($this->values[$key] ?? null) ? $this->values[$key] : 0;

            return $this->values[$key] = $current + $by;
        }

        public function decrement(string $key, int $by = 1): int
        {
            return $this->increment($key, -$by);
        }

        public function forget(string $key): void
        {
            unset($this->values[$key]);
        }

        public function withLock(string $key, float $ttlSeconds, callable $callback): mixed
        {
            return $callback();
        }
    };
}

/** @param  array<string, mixed>  $resilience */
function registryWith(array $resilience, ResilienceStore $store): ResilienceRegistry
{
    return ResilienceRegistry::fromConfig(new Config(new Repository(['firefly' => ['resilience' => $resilience]])), $store);
}

it('gives a circuit-breaker record the default thirty-day idle TTL', function (): void {
    $ttls = [];
    $registry = registryWith(['circuit-breaker' => ['demo' => ['failure-threshold' => 1]]], recordingStore($ttls));

    try {
        $registry->circuitBreaker('demo')->call(static fn (): never => throw new RuntimeException('down'));
    } catch (RuntimeException) {
        // expected
    }

    expect($ttls['firefly:resilience:circuit-breaker:demo'])->toBe(2592000.0);
});

it('honours a configured idle-ttl', function (): void {
    $ttls = [];
    $registry = registryWith(['circuit-breaker' => ['demo' => ['failure-threshold' => 1, 'idle-ttl' => '1h']]], recordingStore($ttls));

    try {
        $registry->circuitBreaker('demo')->call(static fn (): never => throw new RuntimeException('down'));
    } catch (RuntimeException) {
        // expected
    }

    expect($ttls['firefly:resilience:circuit-breaker:demo'])->toBe(3600.0);
});

it('writes no expiry at all when idle-ttl is null', function (): void {
    $ttls = [];
    $registry = registryWith(['rate-limiter' => ['demo' => ['max-tokens' => 1, 'refill-rate' => 1.0, 'idle-ttl' => null]]], recordingStore($ttls));

    $registry->rateLimiter('demo')->call(static fn (): string => 'ok');

    expect($ttls['firefly:resilience:rate-limiter:demo'])->toBeNull();
});

it('refreshes the TTL on every write, so an active key never expires', function (): void {
    $ttls = [];
    $registry = registryWith(['rate-limiter' => ['demo' => ['max-tokens' => 5, 'refill-rate' => 5.0, 'idle-ttl' => '1h']]], recordingStore($ttls));

    $registry->rateLimiter('demo')->call(static fn (): string => 'a');
    $ttls['firefly:resilience:rate-limiter:demo'] = null;
    $registry->rateLimiter('demo')->call(static fn (): string => 'b');

    expect($ttls['firefly:resilience:rate-limiter:demo'])->toBe(3600.0);
});
