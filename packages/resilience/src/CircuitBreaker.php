<?php

declare(strict_types=1);

namespace Firefly\Resilience;

use Firefly\Kernel\Exception\Infrastructure\CircuitBreakerOpenException;
use Firefly\Resilience\Store\ResilienceStore;
use Throwable;

/**
 * A cache-backed circuit breaker. The whole state — CLOSED/OPEN/HALF_OPEN, a bounded window of recent
 * outcomes, the open timestamp, and the half-open probe count — lives in ONE store record keyed by $key, so
 * a trip on one FPM worker is visible to the next. Every transition runs inside store->withLock, making the
 * read-decide-write atomic (no over-admission race). CLOSED opens once the window shows failureThreshold
 * failures (or, when failureRateThreshold is set, that ratio over a full window); OPEN rejects until
 * waitDurationInOpen elapses, then admits up to halfOpenMaxCalls probes; a probe success closes, a probe
 * failure re-opens.
 */
final class CircuitBreaker
{
    private const CLOSED = 'closed';

    private const OPEN = 'open';

    private const HALF_OPEN = 'half_open';

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
            }
            throw $e;
        }

        $this->onSuccess();

        return $result;
    }

    public function state(): string
    {
        return $this->record()['state'];
    }

    private function admit(): void
    {
        $this->store->withLock($this->key, 5.0, function (): void {
            $record = $this->record();

            if ($record['state'] === self::OPEN) {
                if ((microtime(true) - $record['openedAt']) >= $this->waitDurationInOpen) {
                    $record['state'] = self::HALF_OPEN;
                    $record['halfOpenCalls'] = 0;
                } else {
                    throw new CircuitBreakerOpenException;
                }
            }

            if ($record['state'] === self::HALF_OPEN) {
                if ($record['halfOpenCalls'] >= $this->halfOpenMaxCalls) {
                    throw new CircuitBreakerOpenException;
                }
                $record['halfOpenCalls']++;
            }

            $this->save($record);
        });
    }

    private function onSuccess(): void
    {
        $this->store->withLock($this->key, 5.0, function (): void {
            $record = $this->record();

            if ($record['state'] === self::HALF_OPEN) {
                $this->save($this->fresh());

                return;
            }

            $record['outcomes'] = $this->trim([...$record['outcomes'], true]);
            $this->save($record);
        });
    }

    private function onFailure(): void
    {
        $this->store->withLock($this->key, 5.0, function (): void {
            $record = $this->record();
            $record['outcomes'] = $this->trim([...$record['outcomes'], false]);

            if ($record['state'] === self::HALF_OPEN || $this->shouldOpen($record['outcomes'])) {
                $record['state'] = self::OPEN;
                $record['openedAt'] = microtime(true);
                $record['outcomes'] = [];
            }

            $this->save($record);
        });
    }

    /** @return array{state: string, openedAt: float, halfOpenCalls: int, outcomes: list<bool>} */
    private function record(): array
    {
        $raw = $this->store->get($this->key);
        if (! is_array($raw)) {
            return $this->fresh();
        }

        $state = $raw['state'] ?? null;
        $openedAt = $raw['openedAt'] ?? null;
        $halfOpenCalls = $raw['halfOpenCalls'] ?? null;

        return [
            'state' => is_string($state) ? $state : self::CLOSED,
            'openedAt' => is_float($openedAt) ? $openedAt : 0.0,
            'halfOpenCalls' => is_int($halfOpenCalls) ? $halfOpenCalls : 0,
            'outcomes' => $this->boolList($raw['outcomes'] ?? []),
        ];
    }

    /** @return array{state: string, openedAt: float, halfOpenCalls: int, outcomes: list<bool>} */
    private function fresh(): array
    {
        return ['state' => self::CLOSED, 'openedAt' => 0.0, 'halfOpenCalls' => 0, 'outcomes' => []];
    }

    /** @param  array{state: string, openedAt: float, halfOpenCalls: int, outcomes: list<bool>}  $record */
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

    /** @param  list<bool>  $outcomes */
    private function shouldOpen(array $outcomes): bool
    {
        $failures = count(array_filter($outcomes, static fn (bool $ok): bool => $ok === false));

        if ($this->failureRateThreshold !== null) {
            if (count($outcomes) < $this->windowSize) {
                return false;
            }

            return ($failures / count($outcomes)) >= $this->failureRateThreshold;
        }

        return $failures >= $this->failureThreshold;
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
