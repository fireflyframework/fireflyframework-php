<?php

declare(strict_types=1);

namespace Firefly\Orchestration\Saga\Engine;

use Firefly\Orchestration\Core\StepStatus;
use Firefly\Orchestration\Persistence\StateSerializer;
use Throwable;

/** One step of a SagaResult (jafly SagaResult.StepOutcome). */
final readonly class StepOutcome
{
    public function __construct(
        public StepStatus $status,
        public int $attempts,
        public int $latencyMs,
        public mixed $result,
        public ?Throwable $error,
        public bool $compensated,
        public mixed $compensationResult,
        public ?Throwable $compensationError,
    ) {}

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'status' => $this->status->value,
            'attempts' => $this->attempts,
            'latencyMs' => $this->latencyMs,
            'result' => StateSerializer::normalize($this->result, 'result'),
            'error' => $this->error?->getMessage(),
            'compensated' => $this->compensated,
            'compensationResult' => StateSerializer::normalize($this->compensationResult, 'compensationResult'),
            'compensationError' => $this->compensationError?->getMessage(),
        ];
    }
}
