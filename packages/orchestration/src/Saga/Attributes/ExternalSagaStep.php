<?php

declare(strict_types=1);

namespace Firefly\Orchestration\Saga\Attributes;

use Attribute;

/**
 * A step declared on ANOTHER class, joining the saga named by `saga` (jafly @ExternalSagaStep) — for a step
 * that belongs to the bounded context that owns the resource. `compensate` names a method of this same class.
 * Members as #[SagaStep].
 */
#[Attribute(Attribute::TARGET_METHOD)]
final readonly class ExternalSagaStep
{
    /**
     * @param  list<string>  $dependsOn
     */
    public function __construct(
        public string $saga,
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
