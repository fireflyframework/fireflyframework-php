<?php

declare(strict_types=1);

namespace Firefly\Orchestration\Tests\Support;

use Firefly\Config\Config;
use Firefly\Orchestration\Core\Argument\ArgumentResolver;
use Firefly\Orchestration\Core\Callback\CallbackInvoker;
use Firefly\Orchestration\Core\Condition\ConditionEvaluator;
use Firefly\Orchestration\Core\EngineSupport;
use Firefly\Orchestration\Core\ExecutionAdmission;
use Firefly\Orchestration\Core\ExecutionLock;
use Firefly\Orchestration\Core\InvocationRunner;
use Firefly\Orchestration\Core\StepInvoker;
use Firefly\Orchestration\Dlq\DeadLetterService;
use Firefly\Orchestration\Dlq\DeadLetterStore;
use Firefly\Orchestration\Dlq\MemoryDeadLetterStore;
use Firefly\Orchestration\Event\EdaOrchestrationEventPublisher;
use Firefly\Orchestration\Event\NoOpOrchestrationEventPublisher;
use Firefly\Orchestration\Event\NoOpOrchestrationTracing;
use Firefly\Orchestration\OrchestrationSettings;
use Firefly\Orchestration\Persistence\ExecutionPersistenceProvider;
use Firefly\Orchestration\Persistence\MemoryExecutionPersistenceProvider;
use Firefly\Orchestration\Saga\Compensation\CompensationErrorHandlerFactory;
use Firefly\Orchestration\Saga\Compensation\SagaCompensator;
use Firefly\Orchestration\Saga\Engine\SagaEngine;
use Firefly\Orchestration\Saga\Engine\SagaExecutionOrchestrator;
use Firefly\Orchestration\Saga\Registry\SagaRegistry;
use Firefly\Resilience\CircuitBreaker;
use Firefly\Resilience\ResilienceRegistry;
use Firefly\Resilience\Store\InMemoryResilienceStore;
use Firefly\Scheduling\Lock\NoneLock;
use Firefly\Testing\Double\RecordingEventPublisher;
use Illuminate\Config\Repository;
use Illuminate\Container\Container;

/**
 * Every engine wired by hand over in-memory collaborators — what OrchestrationAutoConfiguration wires in an
 * application, minus the container's bean pipeline. `config` is the `firefly.orchestration` section.
 */
final class TestEngines
{
    public readonly Container $container;

    public readonly OrchestrationSettings $settings;

    public readonly MutableClock $clock;

    public readonly ExecutionPersistenceProvider $persistence;

    public readonly DeadLetterStore $deadLetterStore;

    public readonly RecordingOrchestrationEvents $events;

    public readonly RecordingLogger $logger;

    public readonly RecordingEventPublisher $eda;

    public readonly EngineSupport $support;

    public readonly InvocationRunner $runner;

    public readonly StepInvoker $invoker;

    public readonly ConditionEvaluator $conditions;

    public readonly SagaRegistry $sagaRegistry;

    public readonly SagaCompensator $compensator;

    public readonly SagaEngine $sagas;

    /**
     * @param  array<string, mixed>  $config
     */
    private function __construct(array $config, ?ExecutionPersistenceProvider $persistence, ?DeadLetterStore $deadLetters)
    {
        $this->container = new Container;
        $this->settings = OrchestrationSettings::fromConfig(new Config(new Repository(['firefly' => ['orchestration' => $config]])));
        $this->clock = new MutableClock;
        $this->persistence = $persistence ?? new MemoryExecutionPersistenceProvider;
        $this->deadLetterStore = $deadLetters ?? new MemoryDeadLetterStore;
        $this->events = new RecordingOrchestrationEvents;
        $this->logger = new RecordingLogger;
        $this->eda = new RecordingEventPublisher;
        $this->runner = new InvocationRunner($this->container, new ArgumentResolver);
        $tracing = new NoOpOrchestrationTracing;
        $this->invoker = new StepInvoker($this->runner, $tracing);
        $this->conditions = new ConditionEvaluator;
        $resilienceStore = new InMemoryResilienceStore;

        $breaker = $this->settings->backpressure->strategy === 'circuit-breaker'
            ? new CircuitBreaker(
                key: 'firefly:orchestration:backpressure',
                store: $resilienceStore,
                failureThreshold: $this->settings->backpressure->failureThreshold,
                waitDurationInOpen: $this->settings->backpressure->recoveryTimeout,
                halfOpenMaxCalls: $this->settings->backpressure->halfOpenMaxCalls,
            )
            : null;

        $this->support = new EngineSupport(
            persistence: $this->persistence,
            deadLetters: new DeadLetterService($this->deadLetterStore, $this->events, $this->settings->dlq->enabled),
            events: $this->events,
            lifecycle: $this->settings->events->enabled ? new EdaOrchestrationEventPublisher($this->eda, $this->settings->events->defaultTopic) : new NoOpOrchestrationEventPublisher,
            stepEvents: new EdaOrchestrationEventPublisher($this->eda, $this->settings->events->defaultTopic),
            tracing: $tracing,
            callbacks: new CallbackInvoker($this->container, $this->logger),
            clock: $this->clock,
            lock: new ExecutionLock(new NoneLock, $this->settings->lock->ttl, $this->settings->lock->wait),
            admission: new ExecutionAdmission($this->persistence, $this->settings->backpressure, $breaker),
            logger: $this->logger,
        );

        $this->sagaRegistry = new SagaRegistry;
        $this->compensator = new SagaCompensator(
            $this->invoker,
            $this->support,
            $this->settings->saga->compensationPolicy,
            (new CompensationErrorHandlerFactory($this->container, $this->logger))->get($this->settings->saga->compensationErrorHandler),
            ResilienceRegistry::fromConfig(new Config(new Repository(['firefly' => []])), $resilienceStore),
            $resilienceStore,
        );
        $this->sagas = new SagaEngine($this->sagaRegistry, new SagaExecutionOrchestrator($this->invoker, $this->support), $this->compensator, $this->support, $this->settings->saga);
    }

    /**
     * @param  array<string, mixed>  $config
     */
    public static function create(array $config = [], ?ExecutionPersistenceProvider $persistence = null, ?DeadLetterStore $deadLetters = null): self
    {
        return new self($config, $persistence, $deadLetters);
    }
}
