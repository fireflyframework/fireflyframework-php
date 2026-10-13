<?php

declare(strict_types=1);

namespace Firefly\Orchestration\Settings;

/** firefly.orchestration.workflow.* — the queue ASYNC starts go to, and the timer tick's cadence in seconds. */
final readonly class WorkflowSettings
{
    public function __construct(
        public bool $enabled,
        public ?string $queueConnection,
        public ?string $queueName,
        public bool $tickEnabled,
        public float $tickInterval,
    ) {}
}
