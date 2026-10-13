<?php

declare(strict_types=1);

namespace Firefly\Orchestration\Workflow\Registry;

use Firefly\Orchestration\Core\Exception\DefinitionNotFoundException;
use Firefly\Orchestration\Core\Exception\TopologyValidationException;

/** The workflows this application can run, by id; an id is defined once. */
final class WorkflowRegistry
{
    /** @var array<string, WorkflowDefinition> */
    private array $workflows = [];

    public function register(WorkflowDefinition $workflow): void
    {
        if (isset($this->workflows[$workflow->id])) {
            throw new TopologyValidationException("Workflow [{$workflow->id}] is defined twice.");
        }

        $this->workflows[$workflow->id] = $workflow;
    }

    public function get(string $id): WorkflowDefinition
    {
        return $this->workflows[$id] ?? throw new DefinitionNotFoundException("No workflow [{$id}] is registered.");
    }

    public function has(string $id): bool
    {
        return isset($this->workflows[$id]);
    }

    /** @return array<string, WorkflowDefinition> */
    public function all(): array
    {
        return $this->workflows;
    }

    /** @return list<string> */
    public function ids(): array
    {
        return array_map('strval', array_keys($this->workflows));
    }
}
