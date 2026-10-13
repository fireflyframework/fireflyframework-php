<?php

declare(strict_types=1);

namespace Firefly\Orchestration\Workflow\Builder;

use Closure;
use Firefly\Orchestration\Core\ClosureInvocation;
use Firefly\Orchestration\Core\Exception\TopologyValidationException;
use Firefly\Orchestration\Core\StepOptions;
use Firefly\Orchestration\Workflow\Registry\ChildWorkflowDefinition;
use Firefly\Orchestration\Workflow\Registry\SignalWait;
use Firefly\Orchestration\Workflow\Registry\TimerWait;
use Firefly\Orchestration\Workflow\Registry\WaitDefinition;
use Firefly\Orchestration\Workflow\Registry\WorkflowStepDefinition;

/**
 * One step of a WorkflowBuilder. A step that only waits (or only starts a child) may omit its handler: it then
 * passes its input through (for a child step, the input becomes the child's input).
 */
final class WorkflowStepBuilder
{
    /** @var list<string> */
    private array $dependsOn = [];

    private string $name = '';

    private string $description = '';

    private string $condition = '';

    private int $timeoutMs = 0;

    private int $maxRetries = -1;

    private int $retryDelayMs = -1;

    private string $outputEventType = '';

    private ?WaitDefinition $wait = null;

    private ?ChildWorkflowDefinition $child = null;

    private ?Closure $handler = null;

    private ?Closure $compensation = null;

    private ?string $variable = null;

    public function __construct(
        private readonly WorkflowBuilder $workflow,
        private readonly string $workflowId,
        private readonly string $id,
    ) {}

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

    public function dependsOn(string ...$ids): self
    {
        $this->dependsOn = array_values([...$this->dependsOn, ...$ids]);

        return $this;
    }

    public function condition(string $expression): self
    {
        $this->condition = $expression;

        return $this;
    }

    public function timeoutMs(int $milliseconds): self
    {
        $this->timeoutMs = $milliseconds;

        return $this;
    }

    public function retries(int $maxRetries, int $retryDelayMs = -1): self
    {
        $this->maxRetries = $maxRetries;
        $this->retryDelayMs = $retryDelayMs;

        return $this;
    }

    public function outputEventType(string $eventType): self
    {
        $this->outputEventType = $eventType;

        return $this;
    }

    public function waitForSignal(string $name, int $timeoutMs = 0): self
    {
        $this->wait = WaitDefinition::signal($name, $timeoutMs);

        return $this;
    }

    public function waitForTimer(int $delayMs, string $timerId = ''): self
    {
        $this->wait = WaitDefinition::timer($delayMs, $timerId === '' ? "{$this->id}:timer:0" : $timerId);

        return $this;
    }

    /**
     * @param  list<SignalWait>  $signals
     * @param  list<TimerWait>  $timers
     */
    public function waitForAll(array $signals, array $timers = []): self
    {
        $this->wait = WaitDefinition::all($signals, $timers);

        return $this;
    }

    /**
     * @param  list<SignalWait>  $signals
     * @param  list<TimerWait>  $timers
     */
    public function waitForAny(array $signals, array $timers = []): self
    {
        $this->wait = WaitDefinition::any($signals, $timers);

        return $this;
    }

    public function childWorkflow(string $workflowId, bool $waitForCompletion = true, int $timeoutMs = 0): self
    {
        $this->child = new ChildWorkflowDefinition($workflowId, $waitForCompletion, $timeoutMs);

        return $this;
    }

    public function handler(Closure $handler): self
    {
        $this->handler = $handler;

        return $this;
    }

    public function compensation(Closure $compensation): self
    {
        $this->compensation = $compensation;

        return $this;
    }

    public function setVariable(string $name): self
    {
        $this->variable = $name;

        return $this;
    }

    public function add(): WorkflowBuilder
    {
        $handler = $this->handler;
        if ($handler === null) {
            if ($this->wait === null && $this->child === null) {
                throw new TopologyValidationException("Workflow [{$this->workflowId}] step [{$this->id}] has no handler.");
            }
            $handler = static fn (mixed $input): mixed => $input;
        }

        return $this->workflow->addStep(new WorkflowStepDefinition(
            id: $this->id,
            invocation: new ClosureInvocation($handler, "{$this->workflowId}.{$this->id}", $this->variable),
            dependsOn: $this->dependsOn,
            name: $this->name,
            description: $this->description,
            outputEventType: $this->outputEventType,
            options: StepOptions::of(
                $this->maxRetries >= 0 ? $this->maxRetries : $this->workflow->inheritedRetries(),
                $this->retryDelayMs >= 0 ? $this->retryDelayMs : $this->workflow->inheritedRetryDelayMs(),
                $this->timeoutMs,
            ),
            condition: $this->condition,
            compensatable: $this->compensation !== null,
            compensation: $this->compensation === null ? null : new ClosureInvocation($this->compensation, "{$this->workflowId}.{$this->id}:compensate"),
            wait: $this->wait,
            child: $this->child,
        ));
    }
}
