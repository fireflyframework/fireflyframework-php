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
 * `$ttls` holds the TTL of the LAST write per key, which is what most of these cases ask about. `$writes`
 * is the full ledger, one entry per write in order, which is what the rejection cases ask about: "did this
 * call touch the record at all" is a question the last-write map structurally cannot answer.
 *
 * Both arrays are taken BY REFERENCE and bound to the properties in the constructor body rather than
 * promoted: a by-reference promoted property is 8.4 territory, and this suite parses at the framework's 8.3
 * floor. `$writes` has a default so the cases that do not care keep calling `recordingStore($ttls)`.
 *
 * @param  array<string, float|null>  $ttls
 * @param  list<array{key: string, ttl: float|null}>  $writes
 */
function recordingStore(array &$ttls, array &$writes = []): ResilienceStore
{
    return new class($ttls, $writes) implements ResilienceStore
    {
        /** @var array<string, mixed> */
        private array $values = [];

        /** @var array<string, float|null> */
        public array $ttls;

        /** @var list<array{key: string, ttl: float|null}> */
        public array $writes;

        /**
         * @param  array<string, float|null>  $ttls
         * @param  list<array{key: string, ttl: float|null}>  $writes
         */
        public function __construct(array &$ttls, array &$writes)
        {
            $this->ttls = &$ttls;
            $this->writes = &$writes;
        }

        public function get(string $key): mixed
        {
            return $this->values[$key] ?? null;
        }

        public function put(string $key, mixed $value, ?float $ttlSeconds = null): void
        {
            $this->values[$key] = $value;
            $this->ttls[$key] = $ttlSeconds;
            $this->writes[] = ['key' => $key, 'ttl' => $ttlSeconds];
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

/**
 * A store that ENFORCES the TTL it is handed, against a clock the test advances by hand.
 *
 * The recording store above can show that a write happened; it cannot show what the write BUYS. This one
 * can — a record whose expiry has passed is simply gone, exactly as a cache driver would have it — and it
 * does so without a single `usleep()`: the clock is a float the test moves. Laravel's own stores cannot be
 * driven this way, because CacheResilienceStore ceils a TTL to whole seconds, so the shortest expiry that
 * suite could ever observe would cost a real second of wall time per case and would be timing-flaky on a
 * loaded machine. The subject under test is the breaker's write BEHAVIOUR, not the cache's arithmetic.
 *
 * @param  float  $now  the virtual clock, shared by reference with the caller
 */
function expiringStore(float &$now): ResilienceStore
{
    return new class($now) implements ResilienceStore
    {
        /** @var array<string, array{value: mixed, expiresAt: float|null}> */
        private array $entries = [];

        public float $now;

        public function __construct(float &$now)
        {
            $this->now = &$now;
        }

        public function get(string $key): mixed
        {
            $entry = $this->entries[$key] ?? null;

            if ($entry === null) {
                return null;
            }

            if ($entry['expiresAt'] !== null && $entry['expiresAt'] <= $this->now) {
                unset($this->entries[$key]);

                return null;
            }

            return $entry['value'];
        }

        public function put(string $key, mixed $value, ?float $ttlSeconds = null): void
        {
            $this->entries[$key] = [
                'value' => $value,
                'expiresAt' => $ttlSeconds === null ? null : $this->now + $ttlSeconds,
            ];
        }

        public function add(string $key, mixed $value, ?float $ttlSeconds = null): bool
        {
            if ($this->get($key) !== null) {
                return false;
            }
            $this->put($key, $value, $ttlSeconds);

            return true;
        }

        public function increment(string $key, int $by = 1): int
        {
            $current = $this->get($key);
            $next = (is_int($current) ? $current : 0) + $by;
            $this->put($key, $next);

            return $next;
        }

        public function decrement(string $key, int $by = 1): int
        {
            return $this->increment($key, -$by);
        }

        public function forget(string $key): void
        {
            unset($this->entries[$key]);
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

/**
 * A REJECTED call refreshes the TTL, and this is the test that says so.
 *
 * The invariant the feature is sold on — "an active record can never expire, the TTL is always further away
 * than the next call" — was false for the one state where it matters most. A breaker that is OPEN performs
 * no TRANSITION while it sheds load, so with a transition-only refresh its record sat untouched for the
 * entire outage. Configure `wait-duration-in-open: 10m` with `idle-ttl: 60s` and the failure is complete:
 * the breaker trips, rejects for sixty seconds without a single write, the cache reclaims the key, the next
 * call reads a fresh CLOSED record, the ten-minute window never completes, and traffic is admitted to the
 * dependency the breaker already ruled dead — once a minute, for as long as the outage lasts.
 *
 * RateLimiter::consume() already wrote on a refused acquisition for precisely this reason; the breaker now
 * agrees with it.
 */
it('refreshes the idle TTL on a rejected call, so an OPEN breaker shedding load cannot lose its record', function (): void {
    $ttls = [];
    $writes = [];
    // wait-duration-in-open is ten minutes, so nothing in this test can transition: every call after the
    // trip takes admit()'s OPEN-and-window-not-elapsed reject path and nothing else.
    $breaker = new CircuitBreaker(
        'cb:rejecting',
        recordingStore($ttls, $writes),
        failureThreshold: 1,
        waitDurationInOpen: 600.0,
        minimumNumberOfCalls: 1,
        idleTtl: 3600.0,
    );

    try {
        $breaker->call(static fn (): never => throw new RuntimeException('down'));
    } catch (RuntimeException) {
        // the failure that trips it
    }

    $before = count($writes);
    $rejected = 0;
    foreach (range(1, 25) as $ignored) {
        try {
            $breaker->call(static fn (): string => 'unreached');
        } catch (CircuitBreakerOpenException) {
            $rejected++;
        }
    }

    $refreshes = array_slice($writes, $before);

    expect($rejected)->toBe(25)
        ->and($breaker->state())->toBe('open')
        ->and($refreshes)->toHaveCount(25)
        ->and(array_unique(array_column($refreshes, 'ttl')))->toBe([3600.0]);
});

/**
 * The second reject path: HALF_OPEN with every probe permit taken. It is reached here by calling the breaker
 * from INSIDE the probe — the one deterministic way to have the single permit outstanding while another
 * caller asks for one.
 */
it('refreshes the idle TTL when a half-open probe budget is exhausted', function (): void {
    $ttls = [];
    $writes = [];
    $breaker = new CircuitBreaker(
        'cb:probes',
        recordingStore($ttls, $writes),
        failureThreshold: 1,
        waitDurationInOpen: 0.0,
        halfOpenMaxCalls: 1,
        minimumNumberOfCalls: 1,
        halfOpenProbeTimeout: 600.0,
        idleTtl: 3600.0,
    );

    try {
        $breaker->call(static fn (): never => throw new RuntimeException('down'));
    } catch (RuntimeException) {
        // the failure that trips it
    }

    $rejected = 0;
    $refreshes = 0;

    expect($breaker->call(function () use ($breaker, &$writes, &$rejected, &$refreshes): string {
        $before = count($writes);

        foreach (range(1, 5) as $ignored) {
            try {
                $breaker->call(static fn (): string => 'unreached');
            } catch (CircuitBreakerOpenException) {
                $rejected++;
            }
        }

        $refreshes = count($writes) - $before;

        return 'probe ok';
    }))->toBe('probe ok')
        ->and($rejected)->toBe(5)
        ->and($refreshes)->toBe(5)
        ->and($ttls['cb:probes'])->toBe(3600.0);
});

/**
 * The refresh must be a pure TTL refresh: it writes the record it read, `openedAt` included.
 *
 * Stamping the write with "now" — the obvious way to get the refresh wrong — would restart the wait window
 * on every rejected call, and a busy OPEN breaker would then never reach HALF_OPEN at all: the gate would
 * stop being a breaker and become a permanent outage for as long as anyone kept calling it.
 */
it('leaves openedAt untouched when a rejected call refreshes the TTL, so the refresh cannot postpone the probe', function (): void {
    $ttls = [];
    $store = recordingStore($ttls);
    $breaker = new CircuitBreaker('cb:window', $store, failureThreshold: 1, waitDurationInOpen: 600.0, minimumNumberOfCalls: 1);

    try {
        $breaker->call(static fn (): never => throw new RuntimeException('down'));
    } catch (RuntimeException) {
        // the failure that trips it
    }

    $tripped = $store->get('cb:window');
    $openedAt = is_array($tripped) ? $tripped['openedAt'] : null;

    foreach (range(1, 3) as $ignored) {
        try {
            $breaker->call(static fn (): string => 'unreached');
        } catch (CircuitBreakerOpenException) {
            // expected
        }
    }

    $after = $store->get('cb:window');

    expect($openedAt)->toBeFloat()
        ->and(is_array($after) ? $after['openedAt'] : null)->toBe($openedAt)
        ->and(is_array($after) ? $after['state'] : null)->toBe('open');
});

/**
 * The same property, asserted as an OUTCOME rather than as a write count, against a store that really does
 * drop an expired record.
 *
 * This is the regression test for the concrete failure: `idle-ttl` BELOW `wait-duration-in-open`, which is
 * the configuration that used to disable the breaker silently. The clock advances half an idle TTL between
 * calls, so a record refreshed by each rejection always outlives the next call, and the breaker keeps
 * rejecting for the whole (virtual) hour it is open. Before the rejection paths wrote, the third call here
 * was ADMITTED with state `closed`.
 */
it('keeps rejecting for the whole wait window even when idle-ttl is shorter than it', function (): void {
    $now = 0.0;
    $breaker = new CircuitBreaker(
        'cb:short-ttl',
        expiringStore($now),
        failureThreshold: 1,
        waitDurationInOpen: 3600.0,
        minimumNumberOfCalls: 1,
        idleTtl: 60.0,
    );

    try {
        $breaker->call(static fn (): never => throw new RuntimeException('down'));
    } catch (RuntimeException) {
        // the failure that trips it
    }

    $admitted = 0;
    $rejected = 0;
    foreach (range(1, 20) as $ignored) {
        $now += 30.0; // half an idle TTL of silence between callers, twenty times over

        try {
            $breaker->call(static fn (): string => 'ADMITTED');
            $admitted++;
        } catch (CircuitBreakerOpenException) {
            $rejected++;
        }
    }

    expect($rejected)->toBe(20)
        ->and($admitted)->toBe(0)
        ->and($breaker->state())->toBe('open');
});

/**
 * And the documented converse, so nobody reads the case above as "the record is immortal": a breaker that
 * takes NO calls for a whole idle TTL is reclaimed, and rebuilds CLOSED.
 *
 * That is the feature working as designed — the TTL measures idleness, and this breaker is idle — but it is
 * also exactly why the module doc tells an operator to keep `idle-ttl` comfortably above
 * `wait-duration-in-open`: below that margin, "idle" can mean "OPEN with nobody currently calling", and
 * such a breaker forgets it was open. At the shipped default the margin is thirty days against thirty
 * seconds, a factor of eighty-six thousand.
 */
it('reclaims the record of a breaker that takes no calls for a whole idle TTL, rebuilding CLOSED', function (): void {
    $now = 0.0;
    $breaker = new CircuitBreaker(
        'cb:truly-idle',
        expiringStore($now),
        failureThreshold: 1,
        waitDurationInOpen: 3600.0,
        minimumNumberOfCalls: 1,
        idleTtl: 60.0,
    );

    try {
        $breaker->call(static fn (): never => throw new RuntimeException('down'));
    } catch (RuntimeException) {
        // the failure that trips it
    }

    expect($breaker->state())->toBe('open');

    $now += 61.0; // nobody calls for longer than the idle TTL

    expect($breaker->state())->toBe('closed')
        ->and($breaker->call(static fn (): string => 'admitted'))->toBe('admitted');
});
