<?php

declare(strict_types=1);

use Firefly\Observability\Metrics\SimpleMeterRegistry;
use Firefly\Observability\Orchestration\MeterRegistryOrchestrationMetrics;
use Firefly\Orchestration\Core\ExecutionPattern;

it('times executions and steps and counts compensations and dead letters', function (): void {
    $registry = new SimpleMeterRegistry;
    $metrics = new MeterRegistryOrchestrationMetrics($registry);

    $metrics->recordExecution('checkout', ExecutionPattern::Saga, 'success', 0.4);
    $metrics->recordExecution('checkout', ExecutionPattern::Saga, 'failure', 0.2);
    $metrics->recordStep('checkout', 'charge', 'failure', 3, 0.1);
    $metrics->recordCompensation('checkout', 'reserve', 'success');
    $metrics->recordDeadLetter('checkout');
    $metrics->recordDeadLetter('checkout');

    expect($registry->timer('orchestration_executions_seconds', ['name' => 'checkout', 'pattern' => 'saga', 'outcome' => 'success'])->count())->toBe(1)
        ->and($registry->timer('orchestration_executions_seconds', ['name' => 'checkout', 'pattern' => 'saga', 'outcome' => 'failure'])->totalTimeSeconds())->toBe(0.2)
        ->and($registry->timer('orchestration_steps_seconds', ['name' => 'checkout', 'step' => 'charge', 'outcome' => 'failure'])->count())->toBe(1)
        ->and($registry->counter('orchestration_compensations_total', ['name' => 'checkout', 'step' => 'reserve', 'outcome' => 'success'])->count())->toBe(1.0)
        ->and($registry->counter('orchestration_dead_letters_total', ['name' => 'checkout'])->count())->toBe(2.0);
});
