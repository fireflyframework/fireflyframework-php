<?php

declare(strict_types=1);

namespace Firefly\Orchestration\Workflow\Registry;

use Firefly\Orchestration\Core\Invocation;
use Firefly\Orchestration\Core\MethodInvocation;
use Firefly\Orchestration\Core\Rows;
use Firefly\Orchestration\Core\StepOptions;

/** One workflow step (jafly WorkflowStepDefinition); `options` already holds the inherited retries. */
final readonly class WorkflowStepDefinition
{
    /**
     * @param  list<string>  $dependsOn
     */
    public function __construct(
        public string $id,
        public Invocation $invocation,
        public array $dependsOn = [],
        public string $name = '',
        public string $description = '',
        public string $outputEventType = '',
        public StepOptions $options = new StepOptions,
        public string $condition = '',
        public bool $async = false,
        public bool $compensatable = false,
        public ?Invocation $compensation = null,
        public ?WaitDefinition $wait = null,
        public ?ChildWorkflowDefinition $child = null,
    ) {}

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'id' => $this->id, 'invocation' => $this->invocation->toArray(), 'dependsOn' => $this->dependsOn,
            'name' => $this->name, 'description' => $this->description, 'outputEventType' => $this->outputEventType,
            'options' => $this->options->toArray(), 'condition' => $this->condition, 'async' => $this->async,
            'compensatable' => $this->compensatable, 'compensation' => $this->compensation?->toArray(),
            'wait' => $this->wait?->toArray(),
            'child' => $this->child === null ? null : ['workflowId' => $this->child->workflowId, 'waitForCompletion' => $this->child->waitForCompletion, 'timeoutMs' => $this->child->timeoutMs],
        ];
    }

    /** @param array<array-key, mixed> $row */
    public static function fromArray(array $row): self
    {
        $compensation = Rows::nullableMap($row, 'compensation');
        $wait = Rows::nullableMap($row, 'wait');
        $child = Rows::nullableMap($row, 'child');

        return new self(
            id: Rows::string($row, 'id'),
            invocation: MethodInvocation::fromArray(Rows::map($row, 'invocation')),
            dependsOn: Rows::strings($row, 'dependsOn'),
            name: Rows::string($row, 'name'),
            description: Rows::string($row, 'description'),
            outputEventType: Rows::string($row, 'outputEventType'),
            options: StepOptions::fromArray(Rows::map($row, 'options')),
            condition: Rows::string($row, 'condition'),
            async: Rows::bool($row, 'async'),
            compensatable: Rows::bool($row, 'compensatable'),
            compensation: $compensation === null ? null : MethodInvocation::fromArray($compensation),
            wait: $wait === null ? null : WaitDefinition::fromArray($wait),
            child: $child === null ? null : new ChildWorkflowDefinition(Rows::string($child, 'workflowId'), Rows::bool($child, 'waitForCompletion', true), Rows::int($child, 'timeoutMs')),
        );
    }
}
