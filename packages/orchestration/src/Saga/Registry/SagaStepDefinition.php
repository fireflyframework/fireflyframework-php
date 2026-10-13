<?php

declare(strict_types=1);

namespace Firefly\Orchestration\Saga\Registry;

use Firefly\Orchestration\Core\Invocation;
use Firefly\Orchestration\Core\MethodInvocation;
use Firefly\Orchestration\Core\Rows;
use Firefly\Orchestration\Core\StepOptions;

/**
 * One step of a SagaDefinition (jafly SagaStepDefinition): what runs, what undoes it, and how both are
 * retried. The compensation* members are -1 when unset and then inherit the step's own options, without its
 * idempotency key (a compensation is never skipped as "already done").
 */
final readonly class SagaStepDefinition
{
    /**
     * @param  list<string>  $dependsOn
     */
    public function __construct(
        public string $id,
        public Invocation $invocation,
        public array $dependsOn = [],
        public StepOptions $options = new StepOptions,
        public ?Invocation $compensation = null,
        public int $compensationRetry = -1,
        public int $compensationBackoffMs = -1,
        public int $compensationTimeoutMs = -1,
        public bool $compensationCritical = false,
        public bool $cpuBound = false,
        public ?StepEventDefinition $event = null,
    ) {}

    public function compensationOptions(): StepOptions
    {
        return new StepOptions(
            $this->compensationRetry >= 0 ? $this->compensationRetry : $this->options->retry,
            $this->compensationBackoffMs >= 0 ? $this->compensationBackoffMs : $this->options->backoffMs,
            $this->compensationTimeoutMs >= 0 ? $this->compensationTimeoutMs : $this->options->timeoutMs,
            $this->options->jitter,
            $this->options->jitterFactor,
        );
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'invocation' => $this->invocation->toArray(),
            'dependsOn' => $this->dependsOn,
            'options' => $this->options->toArray(),
            'compensation' => $this->compensation?->toArray(),
            'compensationRetry' => $this->compensationRetry,
            'compensationBackoffMs' => $this->compensationBackoffMs,
            'compensationTimeoutMs' => $this->compensationTimeoutMs,
            'compensationCritical' => $this->compensationCritical,
            'cpuBound' => $this->cpuBound,
            'event' => $this->event?->toArray(),
        ];
    }

    /** @param array<array-key, mixed> $row */
    public static function fromArray(array $row): self
    {
        $compensation = Rows::nullableMap($row, 'compensation');
        $event = Rows::nullableMap($row, 'event');

        return new self(
            id: Rows::string($row, 'id'),
            invocation: MethodInvocation::fromArray(Rows::map($row, 'invocation')),
            dependsOn: Rows::strings($row, 'dependsOn'),
            options: StepOptions::fromArray(Rows::map($row, 'options')),
            compensation: $compensation === null ? null : MethodInvocation::fromArray($compensation),
            compensationRetry: Rows::int($row, 'compensationRetry', -1),
            compensationBackoffMs: Rows::int($row, 'compensationBackoffMs', -1),
            compensationTimeoutMs: Rows::int($row, 'compensationTimeoutMs', -1),
            compensationCritical: Rows::bool($row, 'compensationCritical'),
            cpuBound: Rows::bool($row, 'cpuBound'),
            event: $event === null ? null : StepEventDefinition::fromArray($event),
        );
    }
}
