<?php

declare(strict_types=1);

namespace Firefly\Orchestration\Settings;

/**
 * firefly.orchestration.workflow.* — the queue ASYNC starts go to, the timer tick's cadence in seconds, and how
 * many ticks in a row may fail to drive a due execution before it is parked FAILED and dead-lettered.
 */
final readonly class WorkflowSettings
{
    public function __construct(
        public bool $enabled,
        public ?string $queueConnection,
        public ?string $queueName,
        public bool $tickEnabled,
        public float $tickInterval,
        public int $maxResumeAttempts = 5,
    ) {}
}
