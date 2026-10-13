<?php

declare(strict_types=1);

namespace Firefly\Orchestration\Saga\Registry;

use Firefly\Orchestration\Core\Callback\CallbackDefinition;
use Firefly\Orchestration\Core\Exception\DefinitionNotFoundException;
use Firefly\Orchestration\Core\Exception\TopologyValidationException;
use Firefly\Orchestration\Core\Rows;
use Firefly\Orchestration\Core\Topology\TopologyBuilder;

/**
 * A saga (jafly SagaDefinition): steps in declaration order, their topology layers, a trigger event and the
 * lifecycle callbacks. The layers are computed — and the topology validated — in the constructor, so an
 * invalid saga cannot exist at all: the scanner refuses it at firefly:cache time, the registry pass at boot,
 * and a builder at build(). Each step is keyed by its own id, so the array form round-trips unchanged.
 */
final readonly class SagaDefinition
{
    /** @var list<list<string>> */
    public array $layers;

    /**
     * @param  array<string, SagaStepDefinition>  $steps
     * @param  list<CallbackDefinition>  $onComplete
     * @param  list<CallbackDefinition>  $onError
     */
    public function __construct(
        public string $name,
        public array $steps,
        public int $layerConcurrency = 0,
        public string $triggerEventType = '',
        public array $onComplete = [],
        public array $onError = [],
    ) {
        $dependencies = [];
        foreach ($steps as $id => $step) {
            if ((string) $id !== $step->id) {
                throw new TopologyValidationException("Saga [{$name}]: step [{$step->id}] is filed under key [{$id}]; a step must be keyed by its own id.");
            }
            $dependencies[(string) $id] = $step->dependsOn;
        }

        $this->layers = TopologyBuilder::layers("Saga [{$name}]", $dependencies);
    }

    public function step(string $id): SagaStepDefinition
    {
        return $this->steps[$id] ?? throw new DefinitionNotFoundException("Saga [{$this->name}] has no step [{$id}].");
    }

    /** @return list<string> */
    public function stepIds(): array
    {
        return array_map('strval', array_keys($this->steps));
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'name' => $this->name,
            'layerConcurrency' => $this->layerConcurrency,
            'triggerEventType' => $this->triggerEventType,
            'steps' => array_values(array_map(static fn (SagaStepDefinition $step): array => $step->toArray(), $this->steps)),
            'onComplete' => array_map(static fn (CallbackDefinition $callback): array => $callback->toArray(), $this->onComplete),
            'onError' => array_map(static fn (CallbackDefinition $callback): array => $callback->toArray(), $this->onError),
        ];
    }

    /** @param array<array-key, mixed> $row */
    public static function fromArray(array $row): self
    {
        $steps = [];
        foreach (Rows::rows($row, 'steps') as $stepRow) {
            $step = SagaStepDefinition::fromArray($stepRow);
            $steps[$step->id] = $step;
        }

        return new self(
            name: Rows::string($row, 'name'),
            steps: $steps,
            layerConcurrency: Rows::int($row, 'layerConcurrency'),
            triggerEventType: Rows::string($row, 'triggerEventType'),
            onComplete: array_map(static fn (array $callback): CallbackDefinition => CallbackDefinition::fromArray($callback), Rows::rows($row, 'onComplete')),
            onError: array_map(static fn (array $callback): CallbackDefinition => CallbackDefinition::fromArray($callback), Rows::rows($row, 'onError')),
        );
    }
}
