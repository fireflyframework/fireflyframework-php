<?php

declare(strict_types=1);

namespace Firefly\Orchestration\Tests\Support;

use Firefly\Scheduling\Lock\DistributedLock;

/** A DistributedLock whose names can be held by "another process", so a test can make a writer time out. */
final class ScriptedLock implements DistributedLock
{
    /** @var array<string, true> */
    private array $heldElsewhere = [];

    public function holdElsewhere(string $name): void
    {
        $this->heldElsewhere[$name] = true;
    }

    public function releaseElsewhere(string $name): void
    {
        unset($this->heldElsewhere[$name]);
    }

    public function tryAcquire(string $name, float $ttlSeconds): bool
    {
        return ! isset($this->heldElsewhere[$name]);
    }

    public function release(string $name): void {}
}
