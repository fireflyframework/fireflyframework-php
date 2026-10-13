<?php

declare(strict_types=1);

namespace Firefly\Orchestration\Settings;

/** firefly.orchestration.lock.* — how long an execution lock is held at most, and how long a writer waits for it. */
final readonly class LockSettings
{
    public function __construct(
        public float $ttl,
        public float $wait,
    ) {}
}
