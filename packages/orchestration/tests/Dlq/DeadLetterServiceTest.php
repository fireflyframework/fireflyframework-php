<?php

declare(strict_types=1);

use Firefly\Orchestration\Core\Exception\DeadLetterNotFoundException;
use Firefly\Orchestration\Dlq\DeadLetterService;
use Firefly\Orchestration\Dlq\MemoryDeadLetterStore;
use Firefly\Orchestration\Tests\Support\DeadLetterStoreContract;
use Firefly\Orchestration\Tests\Support\RecordingOrchestrationEvents;

it('stores an entry and tells the hooks', function (): void {
    $events = new RecordingOrchestrationEvents;
    $service = new DeadLetterService(new MemoryDeadLetterStore, $events);

    $service->deadLetter(DeadLetterStoreContract::entry('d-1'));

    expect($service->count())->toBe(1)
        ->and($service->get('d-1')->errorMessage)->toBe('card declined')
        ->and($events->calls[0] ?? null)->toBe(['hook' => 'onDeadLettered', 'args' => ['order', 'c-1', 'charge', 'card declined']]);
});

it('drops entries while firefly.orchestration.dlq.enabled is false', function (): void {
    $events = new RecordingOrchestrationEvents;
    $service = new DeadLetterService(new MemoryDeadLetterStore, $events, enabled: false);

    $service->deadLetter(DeadLetterStoreContract::entry('d-1'));

    expect($service->enabled())->toBeFalse()->and($service->count())->toBe(0)->and($events->calls)->toBe([]);
});

it('counts a retry on the entry and refuses an unknown id', function (): void {
    $service = new DeadLetterService(new MemoryDeadLetterStore, new RecordingOrchestrationEvents);
    $service->deadLetter(DeadLetterStoreContract::entry('d-1'));

    $retried = $service->markRetried('d-1', new DateTimeImmutable('2026-10-12T12:00:00+00:00'));

    expect($retried->retryCount)->toBe(1)
        ->and($retried->lastRetriedAt?->format('H:i'))->toBe('12:00')
        ->and(fn () => $service->get('nope'))->toThrow(DeadLetterNotFoundException::class);
});
