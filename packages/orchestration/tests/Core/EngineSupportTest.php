<?php

declare(strict_types=1);

use Firefly\Orchestration\Core\Callback\CallbackInvoker;
use Firefly\Orchestration\Core\EngineSupport;
use Firefly\Orchestration\Core\ExecutionAdmission;
use Firefly\Orchestration\Core\ExecutionContext;
use Firefly\Orchestration\Core\ExecutionLock;
use Firefly\Orchestration\Core\ExecutionPattern;
use Firefly\Orchestration\Core\ExecutionStatus;
use Firefly\Orchestration\Dlq\DeadLetterEntry;
use Firefly\Orchestration\Dlq\DeadLetterService;
use Firefly\Orchestration\Dlq\MemoryDeadLetterStore;
use Firefly\Orchestration\Event\NoOpOrchestrationEventPublisher;
use Firefly\Orchestration\Event\NoOpOrchestrationTracing;
use Firefly\Orchestration\Persistence\ExecutionPersistenceProvider;
use Firefly\Orchestration\Persistence\MemoryExecutionPersistenceProvider;
use Firefly\Orchestration\Settings\BackpressureSettings;
use Firefly\Orchestration\Tests\Support\FailingExecutionPersistence;
use Firefly\Orchestration\Tests\Support\MutableClock;
use Firefly\Orchestration\Tests\Support\RecordingLogger;
use Firefly\Orchestration\Tests\Support\RecordingOrchestrationEvents;
use Firefly\Scheduling\Lock\NoneLock;
use Illuminate\Container\Container;

function orchestrationSupport(ExecutionPersistenceProvider $persistence, RecordingLogger $logger, MemoryDeadLetterStore $dlq): EngineSupport
{
    $events = new RecordingOrchestrationEvents;

    return new EngineSupport(
        persistence: $persistence,
        deadLetters: new DeadLetterService($dlq, $events),
        events: $events,
        lifecycle: new NoOpOrchestrationEventPublisher,
        stepEvents: new NoOpOrchestrationEventPublisher,
        tracing: new NoOpOrchestrationTracing,
        callbacks: new CallbackInvoker(new Container, $logger),
        clock: new MutableClock,
        lock: new ExecutionLock(new NoneLock),
        admission: new ExecutionAdmission($persistence, new BackpressureSettings('none', 10, 0, 5, 30.0, 3)),
        logger: $logger,
    );
}

it('checkpoints the context and survives a failing store by logging', function (): void {
    $logger = new RecordingLogger;
    $persistence = new MemoryExecutionPersistenceProvider;
    $support = orchestrationSupport($persistence, $logger, new MemoryDeadLetterStore);
    $context = ExecutionContext::start(ExecutionPattern::Saga, 'order', [], [], 'c-1', $support->now());

    $support->checkpoint($context, ExecutionStatus::Running);
    expect($persistence->findById('c-1')?->status)->toBe(ExecutionStatus::Running);

    $broken = orchestrationSupport(new FailingExecutionPersistence, $logger, new MemoryDeadLetterStore);
    $state = $broken->checkpoint($context, ExecutionStatus::Completed);

    expect($state->status)->toBe(ExecutionStatus::Completed)
        ->and($logger->messages('warning')[0] ?? '')->toContain('could not be checkpointed');
});

it('dead-letters with the retry count carried by the execution headers', function (): void {
    $dlq = new MemoryDeadLetterStore;
    $support = orchestrationSupport(new MemoryExecutionPersistenceProvider, new RecordingLogger, $dlq);
    $context = ExecutionContext::start(ExecutionPattern::Saga, 'order', ['orderId' => 7], [DeadLetterEntry::RETRY_COUNT_HEADER => '2', DeadLetterEntry::RETRY_OF_HEADER => 'd-0', 'tenant' => 'acme'], 'c-1', $support->now());

    $support->deadLetter($context, 'charge', ExecutionStatus::Failed, new RuntimeException('card declined'));

    $entry = $dlq->findAll()[0];
    expect($entry->retryCount)->toBe(3)
        ->and($entry->input)->toBe(['orderId' => 7])
        ->and($entry->headers)->toBe(['tenant' => 'acme']);
});
