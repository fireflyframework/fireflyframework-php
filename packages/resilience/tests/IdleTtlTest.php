<?php

declare(strict_types=1);

use Firefly\Config\Config;
use Firefly\Kernel\Exception\Infrastructure\CircuitBreakerOpenException;
use Firefly\Resilience\CircuitBreaker;
use Firefly\Resilience\RateLimiter;
use Firefly\Resilience\ResilienceRegistry;
use Firefly\Resilience\Store\CacheResilienceStore;
use Firefly\Resilience\Store\ResilienceStore;
use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\Repository as CacheRepository;
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

/**
 * A NON-POSITIVE idle TTL means "never expire", and this is the test that says so.
 *
 * `0` is what an operator writes to mean "don't expire this" — it is Memcached's convention, it is what
 * `permit-ttl: 0` already does, and `half-open-probe-timeout: 0` is documented two rows above `idle-ttl` as
 * switching a behaviour off. Handed to the store as written it would mean the opposite: Laravel's cache
 * repository turns a TTL <= 0 into `forget($key)`, so every state write would become a DELETE. A negative
 * number gets here the same way — `is_numeric('-5')` is true, so Duration::parse never sees it to refuse it.
 */
it('reads a non-positive idle-ttl as never-expire rather than expire-immediately', function (mixed $configured): void {
    $ttls = [];
    $registry = registryWith([
        'circuit-breaker' => ['demo' => ['failure-threshold' => 1, 'idle-ttl' => $configured]],
        'rate-limiter' => ['demo' => ['max-tokens' => 1, 'refill-rate' => 1.0, 'idle-ttl' => $configured]],
    ], recordingStore($ttls));

    try {
        $registry->circuitBreaker('demo')->call(static fn (): never => throw new RuntimeException('down'));
    } catch (RuntimeException) {
        // expected
    }
    $registry->rateLimiter('demo')->call(static fn (): string => 'ok');

    expect($ttls['firefly:resilience:circuit-breaker:demo'])->toBeNull()
        ->and($ttls['firefly:resilience:rate-limiter:demo'])->toBeNull();
})->with([
    'zero' => [0],
    'zero as a duration' => ['0s'],
    'zero as a float' => [0.0],
    'a negative number' => [-5],
]);

/**
 * The same value, through the REAL cache pipeline this time — Config -> ResilienceRegistry ->
 * CacheResilienceStore -> Illuminate\Cache\Repository -> ArrayStore.
 *
 * The tests above can only see the number a pattern hands its store; they cannot see what a store DOES with
 * it, and that is exactly where `idle-ttl: 0` used to turn both gates into no-ops: the breaker never opened
 * (five straight failures all surfaced as the raw exception, and the record read back NULL) and the limiter
 * granted every acquisition. A fake store structurally cannot catch that, so this one asserts the behaviour
 * an operator is protected by, not the argument the code passes.
 */
it('still opens the breaker and still refuses the limiter when idle-ttl is zero, over the real cache', function (mixed $configured): void {
    $cache = new CacheRepository(new ArrayStore);
    $registry = registryWith([
        'circuit-breaker' => ['demo' => ['failure-threshold' => 1, 'minimum-number-of-calls' => 1, 'idle-ttl' => $configured]],
        // refill-rate 0.0 is the hard-quota shape: one token, never refilled, so a bucket that survives the
        // write refuses for ever after and a bucket that was deleted grants for ever after.
        'rate-limiter' => ['demo' => ['max-tokens' => 1, 'refill-rate' => 0.0, 'idle-ttl' => $configured]],
    ], new CacheResilienceStore($cache));

    $breaker = $registry->circuitBreaker('demo');
    try {
        $breaker->call(static fn (): never => throw new RuntimeException('down'));
    } catch (RuntimeException) {
        // the failure that trips it
    }

    $limiter = $registry->rateLimiter('demo');

    expect(static fn () => $breaker->call(static fn (): string => 'never reached'))->toThrow(CircuitBreakerOpenException::class)
        ->and($breaker->state())->toBe('open')
        ->and($cache->get('firefly:resilience:circuit-breaker:demo'))->not->toBeNull()
        ->and($limiter->tryAcquire())->toBeTrue()
        ->and($limiter->tryAcquire())->toBeFalse()
        ->and($limiter->tryAcquire())->toBeFalse();
})->with([
    'zero' => [0],
    'zero as a duration' => ['0s'],
    'a negative number' => [-5],
]);

/**
 * A pattern built BY HAND is bounded too, because the bound is the constructor's default and not the
 * registry's.
 *
 * This is the case the registry cannot reach and the one where it matters most: the framework's own
 * per-caller limiters (a bucket per OAuth2 client id, a bucket per IP address at the token endpoint) are
 * direct constructions, and their key space grows with TRAFFIC rather than with configuration. With `null`
 * as the parameter's default, every one of those keys was written with no expiry.
 */
it('bounds a directly constructed breaker and limiter with the thirty-day default', function (): void {
    $ttls = [];
    $store = recordingStore($ttls);

    try {
        (new CircuitBreaker('hand:breaker', $store, failureThreshold: 1))->call(static fn (): never => throw new RuntimeException('down'));
    } catch (RuntimeException) {
        // expected
    }
    (new RateLimiter('hand:limiter', $store))->tryAcquire();

    expect($ttls['hand:breaker'])->toBe(CircuitBreaker::DEFAULT_IDLE_TTL)
        ->and($ttls['hand:limiter'])->toBe(RateLimiter::DEFAULT_IDLE_TTL)
        ->and(CircuitBreaker::DEFAULT_IDLE_TTL)->toBe(2592000.0)
        ->and(RateLimiter::DEFAULT_IDLE_TTL)->toBe(2592000.0);
});

it('still lets a directly constructed pattern opt out of any expiry', function (): void {
    $ttls = [];
    $store = recordingStore($ttls);

    (new RateLimiter('hand:unbounded', $store, idleTtl: null))->tryAcquire();

    expect($ttls['hand:unbounded'])->toBeNull();
});
