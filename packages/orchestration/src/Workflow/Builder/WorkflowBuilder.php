<?php

declare(strict_types=1);

namespace Firefly\Orchestration\Workflow\Builder;

use Closure;
use Firefly\Orchestration\Core\Callback\CallbackDefinition;
use Firefly\Orchestration\Core\ClosureInvocation;
use Firefly\Orchestration\Core\Exception\TopologyValidationException;
use Firefly\Orchestration\Core\ExecutionContext;
use Firefly\Orchestration\Core\TriggerMode;
use Firefly\Orchestration\Workflow\Registry\WorkflowDefinition;
use Firefly\Orchestration\Workflow\Registry\WorkflowQueryDefinition;
use Firefly\Orchestration\Workflow\Registry\WorkflowStepDefinition;

/**
 * The programmatic workflow DSL (jafly WorkflowBuilder). Step handlers receive `(mixed $input,
 * ExecutionContext $ctx)`; query closures `(ExecutionContext $ctx, mixed ...$args)`. Register the built
 * workflow in WorkflowRegistry in every process — a durable workflow is resumed (by a signal, the tick or a
 * queue worker) in whatever process gets there, and it is looked up by id.
 */
final class WorkflowBuilder
{
    /** @var array<string, WorkflowStepDefinition> */
    private array $steps = [];

    private string $name = '';

    private string $description = '';

    private string $version = '1.0';

    private TriggerMode $triggerMode = TriggerMode::Sync;

    private string $triggerEventType = '';

    private int $timeoutMs = 0;

    private int $maxRetries = 0;

    private int $retryDelayMs = 1000;

    private bool $publishEvents = false;

    /** @var array<string, WorkflowQueryDefinition> */
    private array $queries = [];

    /** @var list<CallbackDefinition> */
    private array $onStepComplete = [];

    /** @var list<CallbackDefinition> */
    private array $onComplete = [];

    /** @var list<CallbackDefinition> */
    private array $onError = [];

    private function __construct(private readonly string $id) {}

    public static function workflow(string $id): self
    {
        return new self($id);
    }

    public function name(string $name): self
    {
        $this->name = $name;

        return $this;
    }

    public function description(string $description): self
    {
        $this->description = $description;

        return $this;
    }

    public function version(string $version): self
    {
        $this->version = $version;

        return $this;
    }

    public function triggerMode(TriggerMode $mode): self
    {
        $this->triggerMode = $mode;

        return $this;
    }

    public function triggerEventType(string $eventType): self
    {
        $this->triggerEventType = $eventType;

        return $this;
    }

    public function timeoutMs(int $milliseconds): self
    {
        $this->timeoutMs = $milliseconds;

        return $this;
    }

    public function retries(int $maxRetries, int $retryDelayMs = 1000): self
    {
        $this->maxRetries = $maxRetries;
        $this->retryDelayMs = $retryDelayMs;

        return $this;
    }

    public function publishEvents(bool $publish = true): self
    {
        $this->publishEvents = $publish;

        return $this;
    }

    public function step(string $id): WorkflowStepBuilder
    {
        return new WorkflowStepBuilder($this, $this->id, $id);
    }

    /**
     * @param  Closure(ExecutionContext, mixed ...): mixed  $query
     */
    public function query(string $name, Closure $query): self
    {
        $this->queries[$name] = new WorkflowQueryDefinition($name, new ClosureInvocation($query, "{$this->id}?{$name}"));

        return $this;
    }

    /**
     * @param  Closure(ExecutionContext, mixed): void  $callback
     * @param  list<string>  $stepIds
     */
    public function onStepComplete(Closure $callback, array $stepIds = [], int $priority = 0, bool $async = false): self
    {
        $this->onStepComplete[] = CallbackDefinition::closure($callback, $async, $priority, stepIds: $stepIds);

        return $this;
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
     * @param  list<string>  $stepIds
     */
    public function onError(Closure $callback, bool $suppressError = false, array $errorTypes = [], array $stepIds = [], int $priority = 0, bool $async = false): self
    {
        $this->onError[] = CallbackDefinition::closure($callback, $async, $priority, $errorTypes, $suppressError, $stepIds);

        return $this;
    }

    /** @internal the workflow-level retries a step with maxRetries -1 inherits */
    public function inheritedRetries(): int
    {
        return $this->maxRetries;
    }

    /** @internal the workflow-level retry delay a step with retryDelayMs -1 inherits */
    public function inheritedRetryDelayMs(): int
    {
        return $this->retryDelayMs;
    }

    /**
     * @internal called by WorkflowStepBuilder::add(). A second step with an id already added refuses, rather
     * than silently replacing the first.
     */
    public function addStep(WorkflowStepDefinition $step): self
    {
        if (isset($this->steps[$step->id])) {
            throw new TopologyValidationException("Workflow [{$this->id}] declares step [{$step->id}] twice.");
        }

        $this->steps[$step->id] = $step;

        return $this;
    }

    public function build(): WorkflowDefinition
    {
        return new WorkflowDefinition(
            $this->id, $this->steps, $this->name, $this->description, $this->version, $this->triggerMode,
            $this->triggerEventType, $this->timeoutMs, $this->maxRetries, $this->retryDelayMs, $this->publishEvents, 0,
            $this->queries, $this->onStepComplete, $this->onComplete, $this->onError,
        );
    }
}
