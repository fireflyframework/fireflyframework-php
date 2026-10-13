<?php

declare(strict_types=1);

namespace Firefly\Orchestration\Settings;

/**
 * firefly.orchestration.recovery.* — what the sweep does with an execution a crashed process left in a
 * recoverable status for longer than `staleThreshold` seconds: `report` (log it, jafly's behaviour), `resume`
 * (continue from the last checkpoint) or `compensate` (undo what completed). Spec decision D7.
 */
final readonly class RecoverySettings
{
    public const string REPORT = 'report';

    public const string RESUME = 'resume';

    public const string COMPENSATE = 'compensate';

    public function __construct(
        public bool $enabled,
        public float $staleThreshold,
        public string $action,
    ) {}
}
