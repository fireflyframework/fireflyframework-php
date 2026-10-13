<?php

declare(strict_types=1);

namespace Firefly\Orchestration\Saga\Builder;

use Closure;
use Firefly\Orchestration\Core\ClosureInvocation;
use Firefly\Orchestration\Core\Exception\TopologyValidationException;
use Firefly\Orchestration\Core\StepOptions;
use Firefly\Orchestration\Saga\Registry\SagaStepDefinition;
use Firefly\Orchestration\Saga\Registry\StepEventDefinition;

/**
 * One step of a SagaBuilder. A handler receives `(mixed $input, ExecutionContext $ctx)`; a compensation
 * receives `(mixed $result, ExecutionContext $ctx)` — the step's result, with the original input at
 * `$ctx->stepInput($id)`.
 */
final class SagaStepBuilder
{
    /** @var list<string> */
    private array $dependsOn = [];

    private int $retry = 0;

    private int $backoffMs = 0;

    private int $timeoutMs = 0;

    private bool $jitter = false;

    private float $jitterFactor = 0.5;

    private string $idempotencyKey = '';

    private int $compensationRetry = -1;

    private int $compensationBackoffMs = -1;

    private int $compensationTimeoutMs = -1;

    private bool $compensationCritical = false;

    private bool $cpuBound = false;

    private ?StepEventDefinition $event = null;

    private ?string $variable = null;

    private ?Closure $handler = null;

    private ?Closure $compensation = null;

    public function __construct(
        private readonly SagaBuilder $saga,
        private readonly string $sagaName,
        private readonly string $id,
    ) {}

    public function dependsOn(string ...$ids): self
    {
        $this->dependsOn = array_values([...$this->dependsOn, ...$ids]);

        return $this;
    }

    public function retry(int $retry): self
    {
        $this->retry = $retry;

        return $this;
    }

    public function backoffMs(int $milliseconds): self
    {
        $this->backoffMs = $milliseconds;

        return $this;
    }

    public function timeoutMs(int $milliseconds): self
    {
        $this->timeoutMs = $milliseconds;

        return $this;
    }

    public function jitter(bool $enabled = true, float $factor = 0.5): self
    {
        $this->jitter = $enabled;
        $this->jitterFactor = $factor;

        return $this;
    }

    public function idempotencyKey(string $key): self
    {
        $this->idempotencyKey = $key;

        return $this;
    }

    public function compensationRetry(int $retry): self
    {
        $this->compensationRetry = $retry;

        return $this;
    }

    public function compensationBackoffMs(int $milliseconds): self
    {
        $this->compensationBackoffMs = $milliseconds;

        return $this;
    }

    public function compensationTimeoutMs(int $milliseconds): self
    {
        $this->compensationTimeoutMs = $milliseconds;

        return $this;
    }

    public function compensationCritical(bool $critical = true): self
    {
        $this->compensationCritical = $critical;

        return $this;
    }

    public function cpuBound(bool $cpuBound = true): self
    {
        $this->cpuBound = $cpuBound;

        return $this;
    }

    public function stepEvent(string $topic, string $type = '', string $key = ''): self
    {
        $this->event = new StepEventDefinition($topic, $type, $key);

        return $this;
    }

    public function setVariable(string $name): self
    {
        $this->variable = $name;

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

    public function add(): SagaBuilder
    {
        if ($this->handler === null) {
            throw new TopologyValidationException("Saga [{$this->sagaName}] step [{$this->id}] has no handler.");
        }

        return $this->saga->addStep(new SagaStepDefinition(
            id: $this->id,
            invocation: new ClosureInvocation($this->handler, "{$this->sagaName}.{$this->id}", $this->variable),
            dependsOn: $this->dependsOn,
            options: StepOptions::of($this->retry, $this->backoffMs, $this->timeoutMs, $this->jitter, $this->jitterFactor, $this->idempotencyKey),
            compensation: $this->compensation === null ? null : new ClosureInvocation($this->compensation, "{$this->sagaName}.{$this->id}:compensate"),
            compensationRetry: $this->compensationRetry,
            compensationBackoffMs: $this->compensationBackoffMs,
            compensationTimeoutMs: $this->compensationTimeoutMs,
            compensationCritical: $this->compensationCritical,
            cpuBound: $this->cpuBound,
            event: $this->event,
        ));
    }
}
