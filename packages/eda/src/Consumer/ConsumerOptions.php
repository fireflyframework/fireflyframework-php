<?php

declare(strict_types=1);

namespace Firefly\Eda\Consumer;

/** Bounds for a long-running consume: worker-lifetime + idle behaviour. Null = unbounded. */
final readonly class ConsumerOptions
{
    public function __construct(
        public ?int $maxMessages = null,
        public ?int $timeLimit = null,
        public int $pollTimeoutMs = 5000,
        public int $idleSleepMs = 0,
    ) {}
}
