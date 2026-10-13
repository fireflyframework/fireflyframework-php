<?php

declare(strict_types=1);

use Firefly\Orchestration\Core\ExecutionPattern;
use Firefly\Orchestration\Core\TccPhase;
use Firefly\Orchestration\Event\LoggerOrchestrationEvents;
use Firefly\Orchestration\Event\NoOpOrchestrationEventPublisher;
use Firefly\Orchestration\Event\NoOpOrchestrationMetrics;
use Firefly\Orchestration\Event\OrchestrationEvent;
use Firefly\Orchestration\Tests\Support\RecordingLogger;

it('logs starts and successes as info and failures, compensation errors and dead letters as warnings or errors', function (): void {
    $logger = new RecordingLogger;
    $events = new LoggerOrchestrationEvents($logger);

    $events->onStart('order', 'c-1', ExecutionPattern::Saga);
    $events->onStepFailed('order', 'c-1', 'charge', new RuntimeException('card declined'), 3);
    $events->onCompensationStarted('order', 'c-1');
    $events->onStepCompensationFailed('order', 'c-1', 'reserve', new RuntimeException('stock service down'));
    $events->onPhaseFailed('transfer', 'c-2', TccPhase::Confirm, null);
    $events->onCompleted('order', 'c-1', ExecutionPattern::Saga, false, 42);
    $events->onCompleted('ship', 'c-3', ExecutionPattern::Workflow, true, 7);
    $events->onDeadLettered('order', 'c-1', null, 'card declined');
    $events->onStepSuccess('order', 'c-1', 'reserve', 1, 5);

    expect($logger->messages('info'))->toBe([
        'Orchestration SAGA [order] started.',
        'Orchestration [order] is compensating.',
        'Orchestration WORKFLOW [ship] completed in 7 ms.',
    ])->and($logger->messages('warning'))->toBe([
        'Orchestration [order] step [charge] failed after 3 attempt(s): card declined',
        'Orchestration TCC [transfer] phase CONFIRM failed.',
        'Orchestration SAGA [order] failed in 42 ms.',
        'Orchestration [order] was dead-lettered: card declined',
    ])->and($logger->messages('error'))->toBe([
        'Orchestration [order] could not compensate step [reserve]: stock service down',
    ]);
});

it('ships no-op defaults for metrics and lifecycle publishing', function (): void {
    expect(static function (): void {
        (new NoOpOrchestrationMetrics)->recordExecution('order', ExecutionPattern::Saga, 'success', 0.1);
        (new NoOpOrchestrationEventPublisher)->publish(OrchestrationEvent::executionStarted('order', 'c-1', ExecutionPattern::Saga, new DateTimeImmutable));
    })->not->toThrow(Throwable::class);
});

it('carries a normalized step failure payload and the UTC instant in its array form', function (): void {
    $event = OrchestrationEvent::stepFailed('order', 'c-1', ExecutionPattern::Saga, 'charge', new LogicException('nope'), new DateTimeImmutable('2026-10-12T12:00:00+02:00'));

    expect($event->toArray())->toMatchArray([
        'eventType' => 'STEP_FAILED',
        'stepId' => 'charge',
        'status' => null,
        'payload' => ['error' => 'nope', 'errorType' => LogicException::class],
        'timestamp' => '2026-10-12T10:00:00.000000+00:00',
        'key' => null,
    ])->and($event->withTopic('')->topic)->toBeNull();
});
