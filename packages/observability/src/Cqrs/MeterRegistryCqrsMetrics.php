<?php

declare(strict_types=1);

namespace Firefly\Observability\Cqrs;

use Firefly\Cqrs\Metrics\CqrsMetrics;
use Firefly\Observability\Metrics\MetricsRecorder;

/**
 * The real CqrsMetrics recorder — the M10 seam's drop-in. Wins over the shipped NoOpCqrsMetrics because
 * ObservabilityAutoConfiguration (#[Order(500)]) registers it before CqrsAutoConfiguration (#[Order(1000)])
 * evaluates its #[ConditionalOnMissingBean] (§7 risk 4). Records each command/query as a timer tagged by message
 * type + outcome.
 */
final class MeterRegistryCqrsMetrics implements CqrsMetrics
{
    public function __construct(private readonly MetricsRecorder $recorder) {}

    public function recordCommandSuccess(object $command, float $seconds): void
    {
        $this->recorder->record('cqrs_commands_seconds', ['type' => $this->type($command), 'outcome' => 'success'], $seconds);
    }

    public function recordCommandFailure(object $command, float $seconds): void
    {
        $this->recorder->record('cqrs_commands_seconds', ['type' => $this->type($command), 'outcome' => 'failure'], $seconds);
    }

    public function recordQuerySuccess(object $query, float $seconds): void
    {
        $this->recorder->record('cqrs_queries_seconds', ['type' => $this->type($query), 'outcome' => 'success'], $seconds);
    }

    public function recordQueryFailure(object $query, float $seconds): void
    {
        $this->recorder->record('cqrs_queries_seconds', ['type' => $this->type($query), 'outcome' => 'failure'], $seconds);
    }

    private function type(object $message): string
    {
        $class = $message::class;

        return ($pos = strrpos($class, '\\')) !== false ? substr($class, $pos + 1) : $class;
    }
}
