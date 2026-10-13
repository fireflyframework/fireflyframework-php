<?php

declare(strict_types=1);

namespace Firefly\Orchestration\Core;

use Firefly\Orchestration\Persistence\ExecutionPersistenceProvider;
use Firefly\Orchestration\Persistence\ExecutionState;
use Firefly\Orchestration\Saga\Engine\SagaEngine;
use Firefly\Orchestration\Tcc\Engine\TccEngine;
use Firefly\Orchestration\Workflow\Engine\WorkflowEngine;

/** Starts any registered definition by pattern and name — the one door every entry point that is not code uses. */
final class Orchestrator
{
    public function __construct(
        private readonly SagaEngine $sagas,
        private readonly TccEngine $tccs,
        private readonly WorkflowEngine $workflows,
        private readonly ExecutionPersistenceProvider $persistence,
    ) {}

    /**
     * A null $correlationId lets the engine mint a fresh one. A caller that needs a start to be idempotent (the
     * event gateway under at-least-once delivery) passes a stable id instead: every engine refuses an id that
     * already exists, under the execution lock, with an InvalidExecutionStateException.
     *
     * @param  array<string, mixed>  $input
     * @param  array<string, string>  $headers
     */
    public function start(ExecutionPattern $pattern, string $name, array $input = [], array $headers = [], ?string $correlationId = null): ExecutionOutcome
    {
        return match ($pattern) {
            ExecutionPattern::Saga => ExecutionOutcome::fromSaga($this->sagas->execute($name, $input, $headers, $correlationId)),
            ExecutionPattern::Tcc => ExecutionOutcome::fromTcc($this->tccs->execute($name, $input, $headers, $correlationId)),
            ExecutionPattern::Workflow => ExecutionOutcome::fromWorkflow($this->workflows->start($name, $input, $headers, $correlationId)),
        };
    }

    public function find(string $correlationId): ?ExecutionState
    {
        return $this->persistence->findById($correlationId);
    }

    public function sagas(): SagaEngine
    {
        return $this->sagas;
    }

    public function tccs(): TccEngine
    {
        return $this->tccs;
    }

    public function workflows(): WorkflowEngine
    {
        return $this->workflows;
    }
}
