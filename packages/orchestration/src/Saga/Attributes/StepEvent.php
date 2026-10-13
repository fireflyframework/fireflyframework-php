<?php

declare(strict_types=1);

namespace Firefly\Orchestration\Saga\Attributes;

use Attribute;

/**
 * Publishes a STEP_COMPLETED event through firefly/eda when this step succeeds (jafly @StepEvent): on `topic`
 * (default: firefly.orchestration.events.default-topic), as `type` (default: orchestration.step_completed),
 * keyed by `key` — the name of an input key whose value becomes the event key. Always published, whatever
 * firefly.orchestration.events.enabled says (spec decision D9).
 */
#[Attribute(Attribute::TARGET_METHOD)]
final readonly class StepEvent
{
    public function __construct(
        public string $topic = '',
        public string $type = '',
        public string $key = '',
    ) {}
}
