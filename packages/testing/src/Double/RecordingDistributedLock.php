<?php

declare(strict_types=1);

namespace Firefly\Testing\Double;

use Firefly\Scheduling\Lock\DistributedLock;

/** Records lock acquire/release; $available toggles whether tryAcquire() grants the lock. */
final class RecordingDistributedLock implements DistributedLock
{
    /** @var list<array{name: string, ttlSeconds: float}> */
    public array $acquired = [];

    /** @var list<string> */
    public array $released = [];

    public function __construct(private bool $available = true) {}

    public function setAvailable(bool $available): self
    {
        $this->available = $available;

        return $this;
    }

    public function tryAcquire(string $name, float $ttlSeconds): bool
    {
        $this->acquired[] = compact('name', 'ttlSeconds');

        return $this->available;
    }

    public function release(string $name): void
    {
        $this->released[] = $name;
    }
}
