<?php

declare(strict_types=1);

namespace Firefly\Orchestration\Workflow\Attributes;

use Attribute;

/** The step waits until EVERY listed signal has arrived and every listed timer has fired. */
#[Attribute(Attribute::TARGET_METHOD)]
final readonly class WaitForAll
{
    /**
     * @param  list<WaitForSignal>  $signals
     * @param  list<WaitForTimer>  $timers
     */
    public function __construct(
        public array $signals = [],
        public array $timers = [],
    ) {}
}
