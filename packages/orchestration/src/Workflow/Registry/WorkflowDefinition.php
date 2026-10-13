<?php

declare(strict_types=1);

namespace Firefly\Orchestration\Workflow\Registry;

use Firefly\Orchestration\Core\Callback\CallbackDefinition;
use Firefly\Orchestration\Core\Exception\DefinitionNotFoundException;
use Firefly\Orchestration\Core\Exception\TopologyValidationException;
use Firefly\Orchestration\Core\MethodInvocation;
use Firefly\Orchestration\Core\Rows;
use Firefly\Orchestration\Core\Topology\TopologyBuilder;
use Firefly\Orchestration\Core\TriggerMode;

/**
 * A durable workflow (jafly WorkflowDefinition). Layers are computed and the definition validated at
 * construction: the topology, every step keyed by its own id (so the array form round-trips unchanged), and
 * every `compensatable` step having a compensation (a "missing compensation" the boot must refuse rather than
 * discover on the first failure), and every step's wait well formed (WaitDefinition::validate(); the builder
 * skips the attribute scanner, so this is the one gate every path goes through).
 */
final readonly class WorkflowDefinition
{
    /** @var list<list<string>> */
    public array $layers;

    /**
     * @param  array<string, WorkflowStepDefinition>  $steps
     * @param  array<string, WorkflowQueryDefinition>  $queries
     * @param  list<CallbackDefinition>  $onStepComplete
     * @param  list<CallbackDefinition>  $onComplete
     * @param  list<CallbackDefinition>  $onError
     */
    public function __construct(
        public string $id,
        public array $steps,
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
        public array $queries = [],
        public array $onStepComplete = [],
        public array $onComplete = [],
        public array $onError = [],
    ) {
        $dependencies = [];
        foreach ($steps as $stepId => $step) {
            if ((string) $stepId !== $step->id) {
                throw new TopologyValidationException("Workflow [{$id}]: step [{$step->id}] is filed under key [{$stepId}]; a step must be keyed by its own id.");
            }
            $dependencies[(string) $stepId] = $step->dependsOn;
            if ($step->compensatable && $step->compensation === null) {
                throw new TopologyValidationException("Workflow [{$id}] step [{$step->id}] is compensatable but declares no compensation (compensationMethod or #[CompensationStep]).");
            }
            $step->wait?->validate("Workflow [{$id}] step [{$step->id}]");
        }

        $this->layers = TopologyBuilder::layers("Workflow [{$id}]", $dependencies);
    }

    public function step(string $stepId): WorkflowStepDefinition
    {
        return $this->steps[$stepId] ?? throw new DefinitionNotFoundException("Workflow [{$this->id}] has no step [{$stepId}].");
    }

    /** @return list<string> */
    public function stepIds(): array
    {
        return array_map('strval', array_keys($this->steps));
    }

    public function query(string $name): WorkflowQueryDefinition
    {
        return $this->queries[$name] ?? throw new DefinitionNotFoundException("Workflow [{$this->id}] declares no query [{$name}].");
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'id' => $this->id, 'name' => $this->name, 'description' => $this->description, 'version' => $this->version,
            'triggerMode' => $this->triggerMode->value, 'triggerEventType' => $this->triggerEventType, 'timeoutMs' => $this->timeoutMs,
            'maxRetries' => $this->maxRetries, 'retryDelayMs' => $this->retryDelayMs, 'publishEvents' => $this->publishEvents,
            'layerConcurrency' => $this->layerConcurrency,
            'steps' => array_values(array_map(static fn (WorkflowStepDefinition $step): array => $step->toArray(), $this->steps)),
            'queries' => array_values(array_map(static fn (WorkflowQueryDefinition $query): array => ['name' => $query->name, 'invocation' => $query->invocation->toArray(), 'contextFirst' => $query->contextFirst], $this->queries)),
            'onStepComplete' => array_map(static fn (CallbackDefinition $callback): array => $callback->toArray(), $this->onStepComplete),
            'onComplete' => array_map(static fn (CallbackDefinition $callback): array => $callback->toArray(), $this->onComplete),
            'onError' => array_map(static fn (CallbackDefinition $callback): array => $callback->toArray(), $this->onError),
        ];
    }

    /** @param array<array-key, mixed> $row */
    public static function fromArray(array $row): self
    {
        $steps = [];
        foreach (Rows::rows($row, 'steps') as $stepRow) {
            $step = WorkflowStepDefinition::fromArray($stepRow);
            $steps[$step->id] = $step;
        }

        $queries = [];
        foreach (Rows::rows($row, 'queries') as $queryRow) {
            $name = Rows::string($queryRow, 'name');
            $queries[$name] = new WorkflowQueryDefinition($name, MethodInvocation::fromArray(Rows::map($queryRow, 'invocation')), Rows::bool($queryRow, 'contextFirst', true));
        }

        return new self(
            id: Rows::string($row, 'id'),
            steps: $steps,
            name: Rows::string($row, 'name'),
            description: Rows::string($row, 'description'),
            version: Rows::string($row, 'version', '1.0'),
            triggerMode: TriggerMode::tryFrom(Rows::string($row, 'triggerMode')) ?? TriggerMode::Sync,
            triggerEventType: Rows::string($row, 'triggerEventType'),
            timeoutMs: Rows::int($row, 'timeoutMs'),
            maxRetries: Rows::int($row, 'maxRetries'),
            retryDelayMs: Rows::int($row, 'retryDelayMs', 1000),
            publishEvents: Rows::bool($row, 'publishEvents'),
            layerConcurrency: Rows::int($row, 'layerConcurrency'),
            queries: $queries,
            onStepComplete: self::callbacks($row, 'onStepComplete'),
            onComplete: self::callbacks($row, 'onComplete'),
            onError: self::callbacks($row, 'onError'),
        );
    }

    /**
     * @param  array<array-key, mixed>  $row
     * @return list<CallbackDefinition>
     */
    private static function callbacks(array $row, string $key): array
    {
        return array_map(static fn (array $callback): CallbackDefinition => CallbackDefinition::fromArray($callback), Rows::rows($row, $key));
    }
}
