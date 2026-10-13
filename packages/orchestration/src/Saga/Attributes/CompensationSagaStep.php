<?php

declare(strict_types=1);

namespace Firefly\Orchestration\Saga\Attributes;

use Attribute;

/**
 * The compensation of step `forStepId` of saga `saga`, declared on any class (jafly @CompensationSagaStep). A
 * step may have one compensation: declaring it here AND with `compensate:` refuses the scan.
 */
#[Attribute(Attribute::TARGET_METHOD)]
final readonly class CompensationSagaStep
{
    public function __construct(
        public string $saga,
        public string $forStepId,
    ) {}
}
