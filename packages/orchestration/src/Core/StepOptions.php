<?php

declare(strict_types=1);

namespace Firefly\Orchestration\Core;

/**
 * Retry, backoff, jitter, timeout and idempotency for one invocation. The attributes default to -1 ("not
 * set", jafly parity); of() folds every negative into 0, which means "none": no retry, no wait, no timeout.
 * jitterFactor is the share of the backoff added at random to each wait (0.5 → up to +50%).
 */
final readonly class StepOptions
{
    public function __construct(
        public int $retry = 0,
        public int $backoffMs = 0,
        public int $timeoutMs = 0,
        public bool $jitter = false,
        public float $jitterFactor = 0.5,
        public string $idempotencyKey = '',
    ) {}

    public static function of(int $retry, int $backoffMs, int $timeoutMs, bool $jitter = false, float $jitterFactor = 0.5, string $idempotencyKey = ''): self
    {
        return new self(max(0, $retry), max(0, $backoffMs), max(0, $timeoutMs), $jitter, max(0.0, min(1.0, $jitterFactor)), $idempotencyKey);
    }

    public function withoutIdempotency(): self
    {
        return new self($this->retry, $this->backoffMs, $this->timeoutMs, $this->jitter, $this->jitterFactor);
    }

    /** @return array{retry: int, backoffMs: int, timeoutMs: int, jitter: bool, jitterFactor: float, idempotencyKey: string} */
    public function toArray(): array
    {
        return [
            'retry' => $this->retry, 'backoffMs' => $this->backoffMs, 'timeoutMs' => $this->timeoutMs,
            'jitter' => $this->jitter, 'jitterFactor' => $this->jitterFactor, 'idempotencyKey' => $this->idempotencyKey,
        ];
    }

    /** @param array<array-key, mixed> $row */
    public static function fromArray(array $row): self
    {
        return new self(
            Rows::int($row, 'retry'),
            Rows::int($row, 'backoffMs'),
            Rows::int($row, 'timeoutMs'),
            Rows::bool($row, 'jitter'),
            Rows::float($row, 'jitterFactor', 0.5),
            Rows::string($row, 'idempotencyKey'),
        );
    }
}
