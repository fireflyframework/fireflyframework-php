<?php

declare(strict_types=1);

use Firefly\Orchestration\Core\ExecutionContext;
use Firefly\Orchestration\Core\ExecutionPattern;
use Firefly\Orchestration\Core\ExecutionStatus;
use Firefly\Orchestration\Core\StepStatus;
use Firefly\Orchestration\Report\ExecutionReportBuilder;

it('reports every declared step, including the ones that never ran', function (): void {
    $start = new DateTimeImmutable('2026-10-12T10:00:00+00:00');
    $context = ExecutionContext::start(ExecutionPattern::Saga, 'order', [], [], 'c-1', $start);
    $context->setTopologyLayers([['reserve'], ['charge', 'notify']]);
    $context->setStepStatus('reserve', StepStatus::Compensated);
    $context->recordAttempt('reserve');
    $context->setLatencyMs('reserve', 12);
    $context->setStepStatus('charge', StepStatus::Failed);
    $context->recordAttempt('charge');
    $context->recordAttempt('charge');
    $context->putStepError('charge', new RuntimeException('card declined'));

    $report = ExecutionReportBuilder::fromContext($context, ExecutionStatus::Failed, 'card declined', $start->modify('+250 milliseconds'), ['reserve', 'charge', 'notify']);

    expect($report->durationMs)->toBe(250)
        ->and($report->failedSteps())->toBe(['charge'])
        ->and($report->compensatedSteps())->toBe(['reserve'])
        ->and($report->steps['notify']->status)->toBe(StepStatus::Pending)
        ->and($report->toArray())->toMatchArray([
            'name' => 'order', 'correlationId' => 'c-1', 'pattern' => 'SAGA', 'status' => 'FAILED',
            'durationMs' => 250, 'failureReason' => 'card declined', 'layers' => [['reserve'], ['charge', 'notify']],
        ])
        ->and($report->toArray()['steps']['charge'])->toBe([
            'stepId' => 'charge', 'status' => 'FAILED', 'attempts' => 2, 'latencyMs' => 0, 'error' => 'card declined',
            'compensated' => false, 'compensationError' => null,
        ]);
});
