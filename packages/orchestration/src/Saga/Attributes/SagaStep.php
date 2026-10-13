<?php

declare(strict_types=1);

namespace Firefly\Orchestration\Saga\Attributes;

use Attribute;

/**
 * One step of the enclosing #[Saga] (jafly @SagaStep). `compensate` names a public method of the same class
 * that undoes it — it receives the step RESULT as its implicit argument (#[Input] gives the original input).
 * `retry` extra attempts wait `backoffMs` (+ up to `jitterFactor` of it with `jitter`); `timeoutMs` bounds
 * one attempt. An `idempotencyKey` (placeholders {correlationId}, {input.<key>}, {header.<name>}) is
 * recorded once the step succeeds, and a step whose key is already recorded is skipped. The `compensation*`
 * members override retry/backoff/timeout for the compensation; `compensationCritical` makes a failed
 * compensation stop the CIRCUIT_BREAKER policy. `cpuBound` is accepted for parity and has no effect. -1 means
 * "not set".
 */
#[Attribute(Attribute::TARGET_METHOD)]
final readonly class SagaStep
{
    /**
     * @param  list<string>  $dependsOn
     */
    public function __construct(
        public string $id,
        public string $compensate = '',
        public array $dependsOn = [],
        public int $retry = 0,
        public int $backoffMs = -1,
        public int $timeoutMs = -1,
        public bool $jitter = false,
        public float $jitterFactor = 0.5,
        public string $idempotencyKey = '',
        public bool $cpuBound = false,
        public int $compensationRetry = -1,
        public int $compensationTimeoutMs = -1,
        public int $compensationBackoffMs = -1,
        public bool $compensationCritical = false,
    ) {}
}
