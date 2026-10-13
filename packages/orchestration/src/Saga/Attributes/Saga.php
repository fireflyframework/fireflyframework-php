<?php

declare(strict_types=1);

namespace Firefly\Orchestration\Saga\Attributes;

use Attribute;

/**
 * Declares a saga: the class's #[SagaStep] methods are its steps (jafly @Saga). `triggerEventType` starts it
 * from a firefly/eda event with the event payload as input. `layerConcurrency` is accepted for source parity
 * and has no effect: steps of one layer run one after another (spec decision D2). Annotate the class with
 * #[Service] too when its methods need injected collaborators or method interception.
 */
#[Attribute(Attribute::TARGET_CLASS)]
final readonly class Saga
{
    public function __construct(
        public string $name,
        public int $layerConcurrency = 0,
        public string $triggerEventType = '',
    ) {}
}
