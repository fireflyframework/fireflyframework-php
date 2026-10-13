<?php

declare(strict_types=1);

use Firefly\Orchestration\Core\ExecutionContext;
use Firefly\Orchestration\Core\ExecutionPattern;
use Firefly\Orchestration\Core\ExecutionStatus;
use Firefly\Orchestration\Core\StepStatus;
use Firefly\Orchestration\Report\ExecutionReportBuilder;
use Firefly\Orchestration\Saga\Builder\SagaBuilder;
use Firefly\Orchestration\Saga\Engine\SagaResult;

it('summarizes a failed, compensated saga', function (): void {
    $saga = SagaBuilder::saga('order')
        ->step('reserve')->handler(static fn (): string => 'r')->add()
        ->step('charge')->dependsOn('reserve')->handler(static fn (): string => 'c')->add()
        ->build();
    $start = new DateTimeImmutable('2026-10-12T10:00:00+00:00');
    $context = ExecutionContext::start(ExecutionPattern::Saga, 'order', [], ['tenant' => 'acme'], 'c-1', $start);
    $context->putResult('reserve', ['hold' => 'h-1']);
    $context->setStepStatus('reserve', StepStatus::Compensated);
    $context->setStepStatus('charge', StepStatus::Failed);
    $error = new RuntimeException('card declined');
    $context->putStepError('charge', $error);
    $now = $start->modify('+40 milliseconds');

    $result = SagaResult::from($saga, $context, ExecutionStatus::Failed, $error, ExecutionReportBuilder::fromContext($context, ExecutionStatus::Failed, 'card declined', $now, $saga->stepIds()), $now);

    expect($result->isSuccess())->toBeFalse()
        ->and($result->failedSteps())->toBe(['charge'])
        ->and($result->compensatedSteps())->toBe(['reserve'])
        ->and($result->firstErrorStepId())->toBe('charge')
        ->and($result->resultOf('reserve'))->toBe(['hold' => 'h-1'])
        ->and($result->stepResults())->toBe(['reserve' => ['hold' => 'h-1']])
        ->and($result->durationMs())->toBe(40)
        ->and($result->toArray())->toMatchArray(['sagaName' => 'order', 'correlationId' => 'c-1', 'status' => 'FAILED', 'success' => false, 'error' => 'card declined']);
});
