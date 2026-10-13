<?php

declare(strict_types=1);

namespace Firefly\Orchestration\Settings;

use Firefly\Orchestration\Core\CompensationPolicy;

/** firefly.orchestration.tcc.* — `defaultTimeout` applies to a phase that sets no timeoutMs of its own. */
final readonly class TccSettings
{
    public function __construct(
        public bool $enabled,
        public float $defaultTimeout,
        public bool $compositionEnabled,
        public CompensationPolicy $compositionCompensationPolicy,
    ) {}
}
