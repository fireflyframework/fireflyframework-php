<?php

declare(strict_types=1);

namespace Firefly\Observability\HttpExchanges;

/**
 * The default recorder: a bounded FIFO ring in process memory.
 *
 * CORRECT on a long-lived worker (Octane/RoadRunner/Swoole), where one process serves many requests and the ring
 * genuinely fills with a rolling window. USELESS under PHP-FPM, where every request is a fresh process — see
 * HttpExchangeRecorder's docblock for the full failure mode, and point
 * `firefly.observability.httpexchanges.store` at a cache store to fix it. This class does not pretend otherwise:
 * processLocal() returns true and the endpoint prints it.
 *
 * It is the DEFAULT anyway, rather than the cache-backed one, for the same reason SimpleMeterRegistry is: a
 * recorder that silently starts writing to whatever cache an application happens to have configured — on every
 * single request, at a per-request network round trip — is a surprise no framework should spring on an operator.
 * And on the `array` driver the cache-backed one would be no better than this.
 *
 * The eviction is `array_shift` on a plain list rather than a modulo-indexed fixed array. That is O(capacity) per
 * evicting write where the modulo version is O(1), which sounds like the wrong trade until the numbers go in:
 * capacity is 100 by default and hard-capped at 10,000, the shift is a memmove of pointers inside one zval, and
 * it happens once per HTTP request — against which it is unmeasurable. What it buys is that `$this->ring` is
 * always in chronological order with no head index to reason about, so exchanges() is one array_reverse and
 * cannot be off by one.
 */
final class InMemoryHttpExchangeRecorder implements HttpExchangeRecorder
{
    /** @var list<HttpExchange> oldest first */
    private array $ring = [];

    private int $recorded = 0;

    private readonly int $capacity;

    public function __construct(int $capacity = HttpExchangeCapacity::DEFAULT)
    {
        $this->capacity = HttpExchangeCapacity::clamp($capacity);
    }

    public function record(HttpExchange $exchange): void
    {
        $this->recorded++;
        $this->ring[] = $exchange;

        while (count($this->ring) > $this->capacity) {
            array_shift($this->ring);
        }
    }

    /** @return list<HttpExchange> */
    public function exchanges(): array
    {
        return array_reverse($this->ring);
    }

    public function capacity(): int
    {
        return $this->capacity;
    }

    public function recorded(): int
    {
        return $this->recorded;
    }

    public function storage(): string
    {
        return 'memory';
    }

    public function processLocal(): bool
    {
        return true;
    }
}
