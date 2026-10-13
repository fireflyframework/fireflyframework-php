<?php

declare(strict_types=1);

namespace Firefly\Orchestration\Workflow\Attributes;

use Attribute;

/**
 * The step waits, durably, `delayMs` from the moment it is reached; the scheduled tick
 * (firefly:orchestration:tick) resumes the workflow once the timer is due. `timerId` defaults to
 * `<step>:timer:<n>`.
 */
#[Attribute(Attribute::TARGET_METHOD)]
final readonly class WaitForTimer
{
    public function __construct(
        public int $delayMs,
        public string $timerId = '',
    ) {}
}
