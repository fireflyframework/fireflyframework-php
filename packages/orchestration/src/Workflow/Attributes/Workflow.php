<?php

declare(strict_types=1);

namespace Firefly\Orchestration\Workflow\Attributes;

use Attribute;
use Firefly\Orchestration\Core\TriggerMode;

/**
 * Declares a durable workflow (jafly @Workflow): its #[WorkflowStep] methods are the steps. `id` defaults to
 * the class's short name (spec decision D17). `triggerMode: TriggerMode::Async` runs every start on the
 * Laravel queue. `timeoutMs` (0 = none) bounds the WHOLE execution, waits included; `maxRetries` and
 * `retryDelayMs` are what a step with `maxRetries: -1` inherits — both default to "none / 1s", not jafly's
 * 3 retries, because three one-second sleeps per failing step would stall a web request (spec decision D10).
 * `publishEvents` also publishes STEP_COMPLETED for this workflow's steps. `layerConcurrency` has no effect.
 */
#[Attribute(Attribute::TARGET_CLASS)]
final readonly class Workflow
{
    public function __construct(
        public string $id = '',
        public string $name = '',
        public string $description = '',
        public string $version = '1.0',
        public TriggerMode $triggerMode = TriggerMode::Sync,
        public string $triggerEventType = '',
        public int $timeoutMs = 0,
        public int $maxRetries = 0,
        public int $retryDelayMs = 1000,
        public bool $publishEvents = false,
        public int $layerConcurrency = 0,
    ) {}
}
