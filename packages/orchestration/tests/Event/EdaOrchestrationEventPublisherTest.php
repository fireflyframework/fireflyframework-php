<?php

declare(strict_types=1);

use Firefly\Orchestration\Core\ExecutionPattern;
use Firefly\Orchestration\Core\ExecutionStatus;
use Firefly\Orchestration\Event\EdaOrchestrationEventPublisher;
use Firefly\Orchestration\Event\OrchestrationEvent;
use Firefly\Testing\Double\RecordingEventPublisher;

it('publishes lifecycle events on the default topic with an orchestration.* event type', function (): void {
    $bus = new RecordingEventPublisher;
    $now = new DateTimeImmutable('2026-10-12T10:00:00+00:00');

    (new EdaOrchestrationEventPublisher($bus))->publish(OrchestrationEvent::executionCompleted('order', 'c-1', ExecutionPattern::Saga, ExecutionStatus::Completed, $now));

    expect($bus->published)->toHaveCount(1)
        ->and($bus->published[0]['destination'])->toBe('orchestration')
        ->and($bus->published[0]['eventType'])->toBe('orchestration.execution_completed')
        ->and($bus->published[0]['headers'])->toBe(['correlationId' => 'c-1', 'pattern' => 'SAGA'])
        ->and($bus->published[0]['payload'])->toMatchArray(['eventType' => 'EXECUTION_COMPLETED', 'executionName' => 'order', 'status' => 'COMPLETED']);
});

it('honours a step event topic, type and key, and the configured default topic', function (): void {
    $bus = new RecordingEventPublisher;
    $now = new DateTimeImmutable;
    $publisher = new EdaOrchestrationEventPublisher($bus, 'sagas');

    $publisher->publish(OrchestrationEvent::stepCompleted('order', 'c-1', ExecutionPattern::Saga, 'charge', ['amount' => 10], $now)->withTopic('payments')->withType('payment.captured')->withKey('order-7'));
    $publisher->publish(OrchestrationEvent::executionStarted('order', 'c-2', ExecutionPattern::Saga, $now));

    expect($bus->published[0]['destination'])->toBe('payments')
        ->and($bus->published[0]['eventType'])->toBe('payment.captured')
        ->and($bus->published[0]['headers']['key'] ?? null)->toBe('order-7')
        ->and($bus->published[0]['payload']['payload'] ?? null)->toBe(['result' => ['amount' => 10]])
        ->and($bus->published[1]['destination'])->toBe('sagas');
});
