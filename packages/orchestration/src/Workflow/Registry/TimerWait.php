<?php

declare(strict_types=1);

namespace Firefly\Orchestration\Workflow\Registry;

/** A timer a step waits for, `delayMs` after the step is reached. */
final readonly class TimerWait
{
    public function __construct(
        public string $id,
        public int $delayMs,
    ) {}
}
