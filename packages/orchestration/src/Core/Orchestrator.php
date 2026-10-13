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
     * @param  array<string, mixed>  $input
     * @param  array<string, string>  $headers
     */
    public function start(ExecutionPattern $pattern, string $name, array $input = [], array $headers = []): ExecutionOutcome
    {
        return match ($pattern) {
            ExecutionPattern::Saga => ExecutionOutcome::fromSaga($this->sagas->execute($name, $input, $headers)),
            ExecutionPattern::Tcc => ExecutionOutcome::fromTcc($this->tccs->execute($name, $input, $headers)),
            ExecutionPattern::Workflow => ExecutionOutcome::fromWorkflow($this->workflows->start($name, $input, $headers)),
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
