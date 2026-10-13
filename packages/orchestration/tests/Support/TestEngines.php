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
use Firefly\Orchestration\Core\Orchestrator;
use Firefly\Orchestration\Core\StepInvoker;
use Firefly\Orchestration\Dlq\DeadLetterRetrier;
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
use Firefly\Orchestration\Saga\Composition\CompositionDataFlowManager;
use Firefly\Orchestration\Saga\Composition\SagaCompositor;
use Firefly\Orchestration\Saga\Engine\SagaEngine;
use Firefly\Orchestration\Saga\Engine\SagaExecutionOrchestrator;
use Firefly\Orchestration\Saga\Registry\SagaRegistry;
use Firefly\Orchestration\Tcc\Composition\TccCompositor;
use Firefly\Orchestration\Tcc\Engine\TccEngine;
use Firefly\Orchestration\Tcc\Engine\TccExecutionOrchestrator;
use Firefly\Orchestration\Tcc\Registry\TccRegistry;
use Firefly\Orchestration\Workflow\Engine\WaitEvaluator;
use Firefly\Orchestration\Workflow\Engine\WorkflowEngine;
use Firefly\Orchestration\Workflow\Engine\WorkflowExecutor;
use Firefly\Orchestration\Workflow\Registry\WorkflowRegistry;
use Firefly\Resilience\CircuitBreaker;
use Firefly\Resilience\ResilienceRegistry;
use Firefly\Resilience\Store\InMemoryResilienceStore;
use Firefly\Scheduling\Lock\DistributedLock;
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

    public readonly SagaCompositor $compositor;

    public readonly TccRegistry $tccRegistry;

    public readonly TccEngine $tccs;

    public readonly TccCompositor $tccCompositor;

    public readonly WorkflowRegistry $workflowRegistry;

    public readonly RecordingWorkflowDispatcher $dispatcher;

    public readonly WorkflowEngine $workflows;

    public readonly Orchestrator $orchestrator;

    public readonly DeadLetterRetrier $retrier;

    /**
     * @param  array<string, mixed>  $config
     * @param  array<string, mixed>  $resilience
     */
    private function __construct(array $config, ?ExecutionPersistenceProvider $persistence, ?DeadLetterStore $deadLetters, array $resilience, ?DistributedLock $lock)
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
        $resilienceRegistry = ResilienceRegistry::fromConfig(new Config(new Repository(['firefly' => ['resilience' => $resilience]])), $resilienceStore);

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
            lock: new ExecutionLock($lock ?? new NoneLock, $this->settings->lock->ttl, $this->settings->lock->wait),
            admission: new ExecutionAdmission($this->persistence, $this->settings->backpressure, $breaker),
            logger: $this->logger,
        );

        $this->sagaRegistry = new SagaRegistry;
        $this->compensator = new SagaCompensator(
            $this->invoker,
            $this->support,
            $this->settings->saga->compensationPolicy,
            (new CompensationErrorHandlerFactory($this->container, $this->logger))->get($this->settings->saga->compensationErrorHandler),
            $resilienceRegistry,
            $resilienceStore,
        );
        $this->sagas = new SagaEngine($this->sagaRegistry, new SagaExecutionOrchestrator($this->invoker, $this->support), $this->compensator, $this->support, $this->settings->saga);
        $this->compositor = new SagaCompositor($this->sagas, $this->events, $this->conditions, new CompositionDataFlowManager);

        $this->tccRegistry = new TccRegistry;
        $this->tccs = new TccEngine($this->tccRegistry, new TccExecutionOrchestrator($this->invoker, $this->support, $this->settings->tcc), $this->support, $this->settings->tcc);
        $this->tccCompositor = new TccCompositor($this->tccs, $this->events, $this->conditions, new CompositionDataFlowManager, $this->settings->tcc, $resilienceRegistry, $resilienceStore);

        $this->workflowRegistry = new WorkflowRegistry;
        $this->dispatcher = new RecordingWorkflowDispatcher;
        $this->workflows = new WorkflowEngine(
            $this->workflowRegistry,
            new WorkflowExecutor($this->invoker, $this->support, $this->conditions, new WaitEvaluator($this->events)),
            $this->support,
            $this->settings->workflow,
            $this->dispatcher,
            $this->container,
            $this->settings->backpressure->batchSize,
        );
        $this->orchestrator = new Orchestrator($this->sagas, $this->tccs, $this->workflows, $this->persistence);
        $this->retrier = new DeadLetterRetrier($this->support->deadLetters, $this->orchestrator);
    }

    /**
     * @param  array<string, mixed>  $config
     * @param  array<string, mixed>  $resilience  the `firefly.resilience` section (named retry and breaker instances)
     * @param  DistributedLock|null  $lock  the lock backend under ExecutionLock (NoneLock by default)
     */
    public static function create(array $config = [], ?ExecutionPersistenceProvider $persistence = null, ?DeadLetterStore $deadLetters = null, array $resilience = [], ?DistributedLock $lock = null): self
    {
        return new self($config, $persistence, $deadLetters, $resilience, $lock);
    }
}
