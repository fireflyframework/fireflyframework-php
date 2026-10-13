<?php

declare(strict_types=1);

namespace Firefly\Orchestration\Report;

use DateTimeImmutable;
use Firefly\Orchestration\Core\ExecutionContext;
use Firefly\Orchestration\Core\ExecutionStatus;
use Firefly\Orchestration\Core\StepStatus;

/** Builds the report from the live context, listing every declared step — a step that never ran reports PENDING. */
final class ExecutionReportBuilder
{
    /**
     * @param  list<string>  $stepIds
     */
    public static function fromContext(ExecutionContext $context, ExecutionStatus $status, ?string $failureReason, DateTimeImmutable $now, array $stepIds): ExecutionReport
    {
        $steps = [];
        foreach ($stepIds as $stepId) {
            $stepStatus = $context->stepStatus($stepId);
            $steps[$stepId] = new StepReport(
                stepId: $stepId,
                status: $stepStatus,
                attempts: $context->attempts($stepId),
                latencyMs: $context->latencyMs($stepId),
                error: $context->stepError($stepId)?->getMessage(),
                compensated: $stepStatus === StepStatus::Compensated,
                compensationError: $context->compensationError($stepId)?->getMessage(),
            );
        }

        $duration = (int) round(((float) $now->format('U.u') - (float) $context->startedAt()->format('U.u')) * 1000);

        return new ExecutionReport(
            name: $context->executionName,
            correlationId: $context->correlationId,
            pattern: $context->pattern,
            status: $status,
            startedAt: $context->startedAt(),
            completedAt: $now,
            durationMs: max(0, $duration),
            steps: $steps,
            failureReason: $failureReason,
            layers: $context->topologyLayers(),
        );
    }
}
