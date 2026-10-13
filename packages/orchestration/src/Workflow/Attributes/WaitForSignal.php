<?php

declare(strict_types=1);

namespace Firefly\Orchestration\Workflow\Attributes;

use Attribute;

/**
 * The step waits, durably, until signal `name` is delivered (API, REST, firefly:orchestration:signal); its
 * payload is the variable `signals.<name>`. A signal sent before the step is reached is buffered. With
 * `timeoutMs`, the step FAILS (SignalTimeoutException) if the signal has not arrived in time.
 */
#[Attribute(Attribute::TARGET_METHOD)]
final readonly class WaitForSignal
{
    public function __construct(
        public string $name,
        public int $timeoutMs = 0,
    ) {}
}
