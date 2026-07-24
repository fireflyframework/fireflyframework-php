<?php

declare(strict_types=1);

namespace Firefly\Cqrs\Metrics;

/**
 * The default CqrsMetrics: records nothing. The buses call it unconditionally so M12-observability is a bean swap.
 */
final class NoOpCqrsMetrics implements CqrsMetrics
{
    public function recordCommandSuccess(object $command, float $seconds): void {}

    public function recordCommandFailure(object $command, float $seconds): void {}

    public function recordQuerySuccess(object $query, float $seconds): void {}

    public function recordQueryFailure(object $query, float $seconds): void {}
}
