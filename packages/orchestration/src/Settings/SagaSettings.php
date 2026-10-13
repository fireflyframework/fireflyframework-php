<?php

declare(strict_types=1);

namespace Firefly\Orchestration\Settings;

use Firefly\Orchestration\Core\CompensationPolicy;

/** firefly.orchestration.saga.* — `defaultTimeout` is the saga-wide deadline in seconds, 0 for none. */
final readonly class SagaSettings
{
    public function __construct(
        public bool $enabled,
        public CompensationPolicy $compensationPolicy,
        public float $defaultTimeout,
        public string $compensationErrorHandler,
    ) {}
}
