<?php

declare(strict_types=1);

namespace Firefly\Orchestration\Report;

use Firefly\Orchestration\Core\StepStatus;

/** One step's line in an ExecutionReport. */
final readonly class StepReport
{
    public function __construct(
        public string $stepId,
        public StepStatus $status,
        public int $attempts,
        public int $latencyMs,
        public ?string $error = null,
        public bool $compensated = false,
        public ?string $compensationError = null,
    ) {}

    /** @return array{stepId: string, status: string, attempts: int, latencyMs: int, error: ?string, compensated: bool, compensationError: ?string} */
    public function toArray(): array
    {
        return [
            'stepId' => $this->stepId, 'status' => $this->status->value, 'attempts' => $this->attempts,
            'latencyMs' => $this->latencyMs, 'error' => $this->error, 'compensated' => $this->compensated,
            'compensationError' => $this->compensationError,
        ];
    }
}
