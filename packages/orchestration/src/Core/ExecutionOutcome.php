<?php

declare(strict_types=1);

namespace Firefly\Orchestration\Core;

use Firefly\Orchestration\Persistence\ExecutionState;
use Firefly\Orchestration\Saga\Engine\SagaResult;
use Firefly\Orchestration\Tcc\Engine\TccResult;

/**
 * One shape for "I started something" across the three patterns — what the EDA gateway, the scheduler, the
 * DLQ retrier, the REST surface and the commands hand back. `payload` is the pattern's own result as an array.
 */
final readonly class ExecutionOutcome
{
    /**
     * @param  array<string, mixed>  $payload
     */
    public function __construct(
        public string $correlationId,
        public ExecutionPattern $pattern,
        public string $name,
        public ExecutionStatus $status,
        public bool $success,
        public array $payload,
    ) {}

    public static function fromSaga(SagaResult $result): self
    {
        return new self($result->correlationId, ExecutionPattern::Saga, $result->sagaName, $result->status, $result->success, $result->toArray());
    }

    public static function fromTcc(TccResult $result): self
    {
        return new self($result->correlationId, ExecutionPattern::Tcc, $result->tccName, $result->status, $result->success, $result->toArray());
    }

    public static function fromWorkflow(ExecutionState $state): self
    {
        $success = ! in_array($state->status, [ExecutionStatus::Failed, ExecutionStatus::TimedOut, ExecutionStatus::Cancelled], true);

        return new self($state->correlationId, ExecutionPattern::Workflow, $state->executionName, $state->status, $success, $state->toArray());
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'correlationId' => $this->correlationId,
            'pattern' => $this->pattern->value,
            'name' => $this->name,
            'status' => $this->status->value,
            'success' => $this->success,
            'result' => $this->payload,
        ];
    }
}
