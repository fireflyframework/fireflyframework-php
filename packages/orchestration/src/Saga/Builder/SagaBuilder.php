<?php

declare(strict_types=1);

namespace Firefly\Orchestration\Saga\Builder;

use Closure;
use Firefly\Orchestration\Core\Callback\CallbackDefinition;
use Firefly\Orchestration\Core\Exception\TopologyValidationException;
use Firefly\Orchestration\Core\ExecutionContext;
use Firefly\Orchestration\Saga\Registry\SagaDefinition;
use Firefly\Orchestration\Saga\Registry\SagaStepDefinition;

/**
 * The programmatic saga DSL (jafly SagaBuilder):
 *
 *     SagaBuilder::saga('transfer')
 *         ->step('debit')->handler(fn (array $in) => $ledger->debit($in))->compensation(fn ($ref) => $ledger->credit($ref))->add()
 *         ->step('credit')->dependsOn('debit')->handler(fn (array $in) => $ledger->credit($in))->add()
 *         ->build();
 *
 * A built saga runs with SagaEngine::execute($definition, …); register it in SagaRegistry (in every process)
 * for it to be startable by name, triggerable from EDA and recoverable after a crash.
 */
final class SagaBuilder
{
    /** @var array<string, SagaStepDefinition> */
    private array $steps = [];

    private string $triggerEventType = '';

    /** @var list<CallbackDefinition> */
    private array $onComplete = [];

    /** @var list<CallbackDefinition> */
    private array $onError = [];

    private function __construct(
        private readonly string $name,
        private readonly int $layerConcurrency,
    ) {}

    public static function saga(string $name, int $layerConcurrency = 0): self
    {
        return new self($name, $layerConcurrency);
    }

    public function triggerEventType(string $eventType): self
    {
        $this->triggerEventType = $eventType;

        return $this;
    }

    public function step(string $id): SagaStepBuilder
    {
        return new SagaStepBuilder($this, $this->name, $id);
    }

    /**
     * @param  Closure(ExecutionContext, mixed): void  $callback
     */
    public function onComplete(Closure $callback, int $priority = 0, bool $async = false): self
    {
        $this->onComplete[] = CallbackDefinition::closure($callback, $async, $priority);

        return $this;
    }

    /**
     * @param  Closure(ExecutionContext, mixed): void  $callback
     * @param  list<string>  $errorTypes
     */
    public function onError(Closure $callback, bool $suppressError = false, array $errorTypes = [], int $priority = 0, bool $async = false): self
    {
        $this->onError[] = CallbackDefinition::closure($callback, $async, $priority, $errorTypes, $suppressError);

        return $this;
    }

    /**
     * A second step with an id already added refuses, rather than silently replacing the first.
     *
     * @internal called by SagaStepBuilder::add()
     */
    public function addStep(SagaStepDefinition $step): self
    {
        if (isset($this->steps[$step->id])) {
            throw new TopologyValidationException("Saga [{$this->name}] declares step [{$step->id}] twice.");
        }

        $this->steps[$step->id] = $step;

        return $this;
    }

    public function build(): SagaDefinition
    {
        return new SagaDefinition($this->name, $this->steps, $this->layerConcurrency, $this->triggerEventType, $this->onComplete, $this->onError);
    }
}
