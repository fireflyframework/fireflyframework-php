<?php

declare(strict_types=1);

namespace Firefly\Resilience;

use Firefly\Kernel\Exception\Infrastructure\CircuitBreakerOpenException;
use Firefly\Resilience\Store\ResilienceStore;
use Throwable;

/**
 * A cache-backed circuit breaker. The whole state — CLOSED/OPEN/HALF_OPEN, a bounded window of recent
 * outcomes, the open timestamp, and the outstanding half-open probe permits — lives in ONE store record
 * keyed by $key, so a trip on one FPM worker is visible to the next. Every transition runs inside
 * store->withLock, making the read-decide-write atomic (no over-admission race). CLOSED opens once the
 * window shows failureThreshold failures (or, when failureRateThreshold is set, that ratio over a full
 * window), never before minimumNumberOfCalls outcomes have accumulated; OPEN rejects until
 * waitDurationInOpen elapses, then admits up to halfOpenMaxCalls probes; a probe success closes, a probe
 * failure re-opens.
 *
 * HALF_OPEN PROBE PERMITS ARE LEASES, NOT COUNTERS. The breaker used to track the probe budget as a plain
 * `halfOpenCalls` integer that admit() incremented and only onSuccess()/onFailure() ever reset. Two ordinary
 * events left that counter incremented with nothing able to decrement it again:
 *
 *   1. A probe that threw an exception NOT listed in recordOn. call() consulted records() and, for anything
 *      it did not record, rethrew without touching the breaker at all — so the probe slot was consumed and
 *      never returned.
 *   2. A worker that died mid-probe (OOM kill, deploy SIGKILL, fatal error). No `finally` runs in a process
 *      that no longer exists, so the slot was consumed by a caller that will never come back.
 *
 * Either way the breaker sat in HALF_OPEN with halfOpenCalls === halfOpenMaxCalls forever: admit() rejected
 * every subsequent call with CircuitBreakerOpenException, and the ONLY writer that reset the counter was the
 * OPEN->HALF_OPEN transition — which could never fire again, because the breaker was no longer OPEN. A
 * permanently-wedged breaker fails 100% of traffic to a healthy dependency until an operator flushes the
 * cache; it is strictly worse than having no breaker at all. Each probe permit therefore carries an EXPIRY
 * (halfOpenProbeTimeout) and admit() prunes expired permits before counting, so an abandoned probe self-heals
 * after a bounded delay, and call() explicitly returns the permit when the probe throws something recordOn
 * ignores — an ignored exception is neither a success nor a failure (Resilience4j's semantics), so the
 * episode stays HALF_OPEN and simply regains its slot.
 */
final class CircuitBreaker
{
    private const CLOSED = 'closed';

    private const OPEN = 'open';

    private const HALF_OPEN = 'half_open';

    /**
     * How long the store mutex guarding a transition is HELD — not how long a caller waits to take it. That
     * waiting budget belongs to the store (see CacheResilienceStore::$lockBlockTimeout), because it is a
     * fail-fast policy decision, not a property of the critical section. A transition is one read, a handful
     * of array operations and one write, so five seconds of TTL is pure headroom: it exists so that a worker
     * killed inside the section leaves a lock that self-expires instead of a tombstone every later worker
     * deadlocks on.
     */
    private const LOCK_TTL = 5.0;

    /** @param  list<class-string<Throwable>>  $recordOn */
    public function __construct(
        private readonly string $key,
        private readonly ResilienceStore $store,
        private readonly int $failureThreshold = 5,
        private readonly ?float $failureRateThreshold = null,
        private readonly int $windowSize = 10,
        private readonly float $waitDurationInOpen = 30.0,
        private readonly int $halfOpenMaxCalls = 1,
        private readonly array $recordOn = [Throwable::class],
        private readonly int $minimumNumberOfCalls = 0,
        private readonly float $halfOpenProbeTimeout = 30.0,
    ) {}

    /**
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     */
    public function call(callable $callback): mixed
    {
        $this->admit();

        try {
            $result = $callback();
        } catch (Throwable $e) {
            if ($this->records($e)) {
                $this->onFailure();
            } else {
                $this->releaseProbe();
            }
            throw $e;
        }

        $this->onSuccess();

        return $result;
    }

    /**
     * The EFFECTIVE state right now, which is not always the state last written to the store.
     *
     * A breaker that opened and then sat idle keeps `open` in its record until some caller's admit() performs
     * the OPEN->HALF_OPEN transition — the transition is lazy, driven by traffic, and there is no timer to
     * fire it. Every read-only observer (the actuator gauge, an operator, the observability
     * resilience_circuit_breaker_state metric) therefore saw `open` indefinitely for a breaker whose wait
     * window had long since elapsed and which would in fact admit the very next call as a probe. Dashboards
     * showed a hard-down dependency that was not down, and paging on "breaker still open after N minutes"
     * fired on breakers that were only quiet. Reporting the state admit() WOULD decide keeps the gauge and
     * the state machine telling the same story, and — because this method must stay a pure read that an
     * observer can call at any frequency — it computes the answer without writing the transition back.
     */
    public function state(): string
    {
        $record = $this->record();

        if ($record['state'] === self::OPEN && $this->openWindowElapsed($record['openedAt'])) {
            return self::HALF_OPEN;
        }

        return $record['state'];
    }

    private function admit(): void
    {
        $this->store->withLock($this->key, self::LOCK_TTL, function (): void {
            $record = $this->record();

            if ($record['state'] === self::OPEN) {
                if ($this->openWindowElapsed($record['openedAt'])) {
                    $record['state'] = self::HALF_OPEN;
                    $record['halfOpenProbes'] = [];
                } else {
                    throw new CircuitBreakerOpenException;
                }
            }

            if ($record['state'] === self::HALF_OPEN) {
                // Prune first: permits whose lease has run out belong to callers that never came back, and
                // counting them would be counting ghosts. This is the self-heal for a worker killed mid-probe.
                $probes = $this->livingProbes($record['halfOpenProbes']);

                if (count($probes) >= $this->halfOpenMaxCalls) {
                    throw new CircuitBreakerOpenException;
                }

                $probes[] = microtime(true) + $this->halfOpenProbeTimeout;
                $record['halfOpenProbes'] = $probes;
            }

            $this->save($record);
        });
    }

    private function onSuccess(): void
    {
        $this->store->withLock($this->key, self::LOCK_TTL, function (): void {
            $record = $this->record();

            if ($record['state'] === self::HALF_OPEN) {
                // A successful probe closes the breaker outright: a fresh record drops the outcome window,
                // the open timestamp and every outstanding probe permit in one write.
                $this->save($this->fresh());

                return;
            }

            $record['outcomes'] = $this->trim([...$record['outcomes'], true]);
            $this->save($record);
        });
    }

    private function onFailure(): void
    {
        $this->store->withLock($this->key, self::LOCK_TTL, function (): void {
            $record = $this->record();
            $record['outcomes'] = $this->trim([...$record['outcomes'], false]);

            if ($record['state'] === self::HALF_OPEN || $this->shouldOpen($record['outcomes'])) {
                $record['state'] = self::OPEN;
                $record['openedAt'] = microtime(true);
                $record['outcomes'] = [];
                // Re-opening ends the half-open episode, so no permit from it may survive into the next one:
                // a leftover lease would silently eat part of the NEXT episode's probe budget.
                $record['halfOpenProbes'] = [];
            }

            $this->save($record);
        });
    }

    /**
     * Returns the HALF_OPEN probe permit taken by admit() WITHOUT recording an outcome.
     *
     * This is the release path for an exception outside recordOn — the case that used to wedge the breaker
     * permanently (see the class docblock). Such an exception is deliberately invisible to the breaker's
     * statistics: it is not evidence the dependency is failing, so it must not re-open the breaker, and it is
     * not evidence the dependency recovered, so it must not close it. The one thing it MUST do is give back
     * the slot it took, leaving the breaker in the well-defined state it was already in: still HALF_OPEN,
     * still waiting for a probe that produces a real verdict.
     *
     * The permit removed is the newest live one. admit() stamps each permit with `now + halfOpenProbeTimeout`,
     * so permits are ordered by claim time and the newest is the one this caller just took; dropping the
     * OLDEST instead would hand a slot back on behalf of a probe that is still running and let the episode
     * over-admit.
     */
    private function releaseProbe(): void
    {
        try {
            $this->releasedProbe();
        } catch (Throwable) {
            // Best effort, and deliberately silent. This runs inside call()'s catch block, so anything that
            // escapes here REPLACES the exception the caller actually threw: a store too contended to answer
            // would turn an exception the breaker was configured to IGNORE into an infrastructure 503 the
            // application never raised, discarding the real error on the way. Giving up costs one probe slot
            // until its lease expires, which is exactly the abandoned-permit case halfOpenProbeTimeout
            // already exists to heal — a bounded, self-correcting loss, against destroying the caller's own
            // failure. The store is the only thing that can throw here, and it is the thing that is unwell.
        }
    }

    /** The write behind releaseProbe(), separated so the swallow above wraps nothing but the store call. */
    private function releasedProbe(): void
    {
        $this->store->withLock($this->key, self::LOCK_TTL, function (): void {
            $record = $this->record();

            if ($record['state'] !== self::HALF_OPEN) {
                return;
            }

            $probes = $this->livingProbes($record['halfOpenProbes']);
            if ($probes !== []) {
                $index = array_search(max($probes), $probes, true);
                if ($index !== false) {
                    unset($probes[$index]);
                }
            }

            $record['halfOpenProbes'] = array_values($probes);
            $this->save($record);
        });
    }

    private function openWindowElapsed(float $openedAt): bool
    {
        return (microtime(true) - $openedAt) >= $this->waitDurationInOpen;
    }

    /**
     * Drops probe permits whose lease has expired.
     *
     * A permit is held with `>` rather than `>=` on purpose: it makes halfOpenProbeTimeout: 0.0 mean "do not
     * hold probe slots at all" (every half-open call is admitted), which is the only sane reading of a
     * zero-length lease and keeps the degenerate configuration from wedging exactly the way the old counter
     * did. Any positive value bounds the wedge a dead worker can cause to that many seconds.
     *
     * @param  list<float>  $probes
     * @return list<float>
     */
    private function livingProbes(array $probes): array
    {
        $now = microtime(true);

        $living = [];
        foreach ($probes as $deadline) {
            if ($deadline > $now) {
                $living[] = $deadline;
            }
        }

        return $living;
    }

    /**
     * Reads the persisted record, tolerating anything the cache hands back.
     *
     * Note the upgrade path: a record written by an older deploy carries `halfOpenCalls` (an int) and no
     * `halfOpenProbes`, so it is read as "no outstanding probes". That is deliberate — a breaker already
     * wedged by the old counter heals the moment this version reads its record, rather than carrying the
     * wedge across the deploy that fixes it.
     *
     * @return array{state: string, openedAt: float, halfOpenProbes: list<float>, outcomes: list<bool>}
     */
    private function record(): array
    {
        $raw = $this->store->get($this->key);
        if (! is_array($raw)) {
            return $this->fresh();
        }

        $state = $raw['state'] ?? null;
        $openedAt = $raw['openedAt'] ?? null;

        return [
            'state' => is_string($state) ? $state : self::CLOSED,
            'openedAt' => is_float($openedAt) ? $openedAt : 0.0,
            'halfOpenProbes' => $this->floatList($raw['halfOpenProbes'] ?? []),
            'outcomes' => $this->boolList($raw['outcomes'] ?? []),
        ];
    }

    /** @return array{state: string, openedAt: float, halfOpenProbes: list<float>, outcomes: list<bool>} */
    private function fresh(): array
    {
        return ['state' => self::CLOSED, 'openedAt' => 0.0, 'halfOpenProbes' => [], 'outcomes' => []];
    }

    /** @param  array{state: string, openedAt: float, halfOpenProbes: list<float>, outcomes: list<bool>}  $record */
    private function save(array $record): void
    {
        $this->store->put($this->key, $record);
    }

    /**
     * @param  list<bool>  $outcomes
     * @return list<bool>
     */
    private function trim(array $outcomes): array
    {
        return array_slice($outcomes, -$this->windowSize);
    }

    /**
     * Decides whether the CLOSED window has seen enough failure to trip.
     *
     * minimumNumberOfCalls is the statistical floor Resilience4j calls the same thing: below it the window
     * holds too little evidence to justify taking a dependency out of service. Without one, a breaker
     * configured with failure-threshold 1 trips on the first failure a fresh window ever sees — a single
     * blip on a low-traffic endpoint blackholes the dependency for wait-duration-in-open, and because a
     * probe failure re-opens immediately, one flaky call per wait window is enough to keep it open forever.
     *
     * @param  list<bool>  $outcomes
     */
    private function shouldOpen(array $outcomes): bool
    {
        $recorded = count($outcomes);

        if ($recorded < $this->minimumCalls()) {
            return false;
        }

        $failures = count(array_filter($outcomes, static fn (bool $ok): bool => $ok === false));

        if ($this->failureRateThreshold !== null) {
            if ($recorded < $this->windowSize) {
                return false;
            }

            return ($failures / $recorded) >= $this->failureRateThreshold;
        }

        return $failures >= $this->failureThreshold;
    }

    /**
     * The minimum clamped to what the window can physically hold.
     *
     * The outcome window is trimmed to windowSize, so a minimumNumberOfCalls above it could never be reached
     * and the breaker would never open at all. Clamping rather than throwing is the deliberate choice: this
     * runs on the hot path of every recorded failure, and the failure mode of a config typo must be "the
     * breaker still protects you" rather than "protection is silently off" or "the guarded call now throws a
     * configuration error instead of the real one".
     */
    private function minimumCalls(): int
    {
        return max(0, min($this->minimumNumberOfCalls, $this->windowSize));
    }

    /** @return list<bool> */
    private function boolList(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        $out = [];
        foreach ($value as $entry) {
            $out[] = (bool) $entry;
        }

        return $out;
    }

    /** @return list<float> */
    private function floatList(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        $out = [];
        foreach ($value as $entry) {
            if (is_int($entry) || is_float($entry)) {
                $out[] = (float) $entry;
            }
        }

        return $out;
    }

    private function records(Throwable $e): bool
    {
        foreach ($this->recordOn as $type) {
            if ($e instanceof $type) {
                return true;
            }
        }

        return false;
    }
}
