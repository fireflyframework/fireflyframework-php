<?php

declare(strict_types=1);

namespace Firefly\Testing\Double;

use Firefly\Cqrs\Metrics\CqrsMetrics;

/** Records every metrics callback the buses fire, so a test can assert success/failure counts + durations. */
final class RecordingCqrsMetrics implements CqrsMetrics
{
    /** @var list<array{command: object, seconds: float}> */
    public array $commandSuccesses = [];

    /** @var list<array{command: object, seconds: float}> */
    public array $commandFailures = [];

    /** @var list<array{query: object, seconds: float}> */
    public array $querySuccesses = [];

    /** @var list<array{query: object, seconds: float}> */
    public array $queryFailures = [];

    public function recordCommandSuccess(object $command, float $seconds): void
    {
        $this->commandSuccesses[] = compact('command', 'seconds');
    }

    public function recordCommandFailure(object $command, float $seconds): void
    {
        $this->commandFailures[] = compact('command', 'seconds');
    }

    public function recordQuerySuccess(object $query, float $seconds): void
    {
        $this->querySuccesses[] = compact('query', 'seconds');
    }

    public function recordQueryFailure(object $query, float $seconds): void
    {
        $this->queryFailures[] = compact('query', 'seconds');
    }
}
