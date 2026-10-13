<?php

declare(strict_types=1);

use Firefly\Orchestration\Core\ExecutionPattern;
use Firefly\Orchestration\Event\MetricsOrchestrationEvents;
use Firefly\Orchestration\Tests\Support\RecordingOrchestrationMetrics;

it('turns hooks into execution, step, compensation and dead-letter metrics', function (): void {
    $metrics = new RecordingOrchestrationMetrics;
    $events = new MetricsOrchestrationEvents($metrics);

    $events->onStepSuccess('order', 'c', 'reserve', 2, 15);
    $events->onStepFailed('order', 'c', 'charge', new RuntimeException('x'), 3);
    $events->onStepCompensated('order', 'c', 'reserve');
    $events->onStepCompensationFailed('order', 'c', 'reserve', new RuntimeException('y'));
    $events->onCompleted('order', 'c', ExecutionPattern::Saga, false, 120);
    $events->onDeadLettered('order', 'c', 'charge', 'x');

    expect($metrics->records)->toBe([
        'step order.reserve success x2',
        'step order.charge failure x3',
        'compensation order.reserve compensated',
        'compensation order.reserve failed',
        'execution order SAGA failure',
        'dead-letter order',
    ]);
});
