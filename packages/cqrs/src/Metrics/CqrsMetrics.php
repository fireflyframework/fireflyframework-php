<?php

declare(strict_types=1);

namespace Firefly\Cqrs\Metrics;

/**
 * The metrics SEAM both buses call unconditionally (success/failure + duration in seconds). NoOpCqrsMetrics is the
 * shipped default; the real recorder (Micrometer-equivalent) wires in M12-observability as a drop-in bean swap.
 */
interface CqrsMetrics
{
    public function recordCommandSuccess(object $command, float $seconds): void;

    public function recordCommandFailure(object $command, float $seconds): void;

    public function recordQuerySuccess(object $query, float $seconds): void;

    public function recordQueryFailure(object $query, float $seconds): void;
}
