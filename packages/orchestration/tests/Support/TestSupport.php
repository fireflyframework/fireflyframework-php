<?php

declare(strict_types=1);

namespace Firefly\Orchestration\Tests\Support;

use Firefly\Orchestration\Core\Argument\ArgumentResolver;
use Firefly\Orchestration\Core\Callback\CallbackInvoker;
use Firefly\Orchestration\Core\EngineSupport;
use Firefly\Orchestration\Core\ExecutionAdmission;
use Firefly\Orchestration\Core\ExecutionLock;
use Firefly\Orchestration\Core\InvocationRunner;
use Firefly\Orchestration\Core\StepInvoker;
use Firefly\Orchestration\Dlq\DeadLetterService;
use Firefly\Orchestration\Dlq\DeadLetterStore;
use Firefly\Orchestration\Dlq\MemoryDeadLetterStore;
use Firefly\Orchestration\Event\NoOpOrchestrationEventPublisher;
use Firefly\Orchestration\Event\NoOpOrchestrationTracing;
use Firefly\Orchestration\Event\OrchestrationEventPublisher;
use Firefly\Orchestration\Event\OrchestrationEvents;
use Firefly\Orchestration\Persistence\ExecutionPersistenceProvider;
use Firefly\Orchestration\Persistence\MemoryExecutionPersistenceProvider;
use Firefly\Orchestration\Settings\BackpressureSettings;
use Firefly\Scheduling\Lock\NoneLock;
use Illuminate\Container\Container;

/** Builds the shared engine collaborators with in-memory defaults; each argument overrides one of them. */
final class TestSupport
{
    public static function engineSupport(
        ?ExecutionPersistenceProvider $persistence = null,
        ?OrchestrationEvents $events = null,
        ?RecordingLogger $logger = null,
        ?MutableClock $clock = null,
        ?DeadLetterStore $deadLetters = null,
        ?OrchestrationEventPublisher $lifecycle = null,
        ?OrchestrationEventPublisher $stepEvents = null,
        ?BackpressureSettings $backpressure = null,
    ): EngineSupport {
        $persistence ??= new MemoryExecutionPersistenceProvider;
        $events ??= new RecordingOrchestrationEvents;
        $logger ??= new RecordingLogger;

        return new EngineSupport(
            persistence: $persistence,
            deadLetters: new DeadLetterService($deadLetters ?? new MemoryDeadLetterStore, $events),
            events: $events,
            lifecycle: $lifecycle ?? new NoOpOrchestrationEventPublisher,
            stepEvents: $stepEvents ?? new NoOpOrchestrationEventPublisher,
            tracing: new NoOpOrchestrationTracing,
            callbacks: new CallbackInvoker(new Container, $logger),
            clock: $clock ?? new MutableClock,
            lock: new ExecutionLock(new NoneLock),
            admission: new ExecutionAdmission($persistence, $backpressure ?? new BackpressureSettings('none', 10, 0, 5, 30.0, 3)),
            logger: $logger,
        );
    }

    public static function invoker(): StepInvoker
    {
        return new StepInvoker(new InvocationRunner(new Container, new ArgumentResolver), new NoOpOrchestrationTracing);
    }
}
