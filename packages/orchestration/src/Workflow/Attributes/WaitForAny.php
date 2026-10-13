<?php

declare(strict_types=1);

namespace Firefly\Orchestration\Workflow\Attributes;

use Attribute;

/**
 * The step waits until ANY listed signal arrives or any listed timer fires; the variable `wait.trigger`
 * names which one did (`approved`, or the timer id).
 */
#[Attribute(Attribute::TARGET_METHOD)]
final readonly class WaitForAny
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
