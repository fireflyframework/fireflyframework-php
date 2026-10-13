<?php

declare(strict_types=1);

namespace Firefly\Orchestration;

use Firefly\Config\Config;
use Firefly\Container\Attributes\Bean;
use Firefly\Container\Attributes\Configuration;
use Firefly\Container\Attributes\Lazy;
use Firefly\Container\Attributes\Order;
use Firefly\Container\Container as FireflyContainer;
use Firefly\Context\Condition\Attributes\ConditionalOnMissingBean;
use Firefly\Eda\EventPublisher;
use Firefly\Kernel\Exception\Framework\ConfigurationException;
use Firefly\Orchestration\Core\Argument\ArgumentResolver;
use Firefly\Orchestration\Core\Callback\CallbackInvoker;
use Firefly\Orchestration\Core\Clock;
use Firefly\Orchestration\Core\Condition\ConditionEvaluator;
use Firefly\Orchestration\Core\EngineSupport;
use Firefly\Orchestration\Core\ExecutionAdmission;
use Firefly\Orchestration\Core\ExecutionLock;
use Firefly\Orchestration\Core\InvocationRunner;
use Firefly\Orchestration\Core\Orchestrator;
use Firefly\Orchestration\Core\StepInvoker;
use Firefly\Orchestration\Core\SystemClock;
use Firefly\Orchestration\Dlq\DatabaseDeadLetterStore;
use Firefly\Orchestration\Dlq\DeadLetterRetrier;
use Firefly\Orchestration\Dlq\DeadLetterService;
use Firefly\Orchestration\Dlq\DeadLetterStore;
use Firefly\Orchestration\Dlq\MemoryDeadLetterStore;
use Firefly\Orchestration\Event\CompositeOrchestrationEvents;
use Firefly\Orchestration\Event\EdaOrchestrationEventPublisher;
use Firefly\Orchestration\Event\EventGateway;
use Firefly\Orchestration\Event\LoggerOrchestrationEvents;
use Firefly\Orchestration\Event\MetricsOrchestrationEvents;
use Firefly\Orchestration\Event\NoOpOrchestrationEventPublisher;
use Firefly\Orchestration\Event\NoOpOrchestrationMetrics;
use Firefly\Orchestration\Event\NoOpOrchestrationTracing;
use Firefly\Orchestration\Event\OrchestrationEventPublisher;
use Firefly\Orchestration\Event\OrchestrationEvents;
use Firefly\Orchestration\Event\OrchestrationMetrics;
use Firefly\Orchestration\Event\OrchestrationTracing;
use Firefly\Orchestration\Persistence\CacheExecutionPersistenceProvider;
use Firefly\Orchestration\Persistence\DatabaseExecutionPersistenceProvider;
use Firefly\Orchestration\Persistence\ExecutionPersistenceProvider;
use Firefly\Orchestration\Persistence\MemoryExecutionPersistenceProvider;
use Firefly\Orchestration\Recovery\RecoveryService;
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
use Firefly\Orchestration\Workflow\Queue\BusWorkflowDispatcher;
use Firefly\Orchestration\Workflow\Queue\WorkflowDispatcher;
use Firefly\Orchestration\Workflow\Registry\WorkflowRegistry;
use Firefly\Resilience\CircuitBreaker;
use Firefly\Resilience\ResilienceRegistry;
use Firefly\Resilience\Store\ResilienceStore;
use Firefly\Scheduling\Lock\DistributedLock;
use Illuminate\Container\Container;
use Illuminate\Contracts\Bus\Dispatcher;
use Illuminate\Contracts\Cache\Factory as CacheFactory;
use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\ConnectionResolverInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * Wires firefly/orchestration (jafly's Orchestration, Saga, Tcc and Workflow auto-configurations, folded into
 * one class). #[Order(1000)] places it after user definitions, and every bean backs off #[ConditionalOnMissingBean]
 * so an application — or W3's event-sourced persistence with a lower #[Order] — supplies its own and wins.
 *
 * Only the settings are EAGER, so a configuration the package cannot honour refuses the boot. Everything else
 * is #[Lazy]: a request that never starts an execution builds no engine, and an application without a queue
 * dispatcher bound boots as long as it never starts an ASYNC workflow.
 */
#[Configuration]
#[Order(1000)]
final class OrchestrationAutoConfiguration
{
    #[Bean]
    #[ConditionalOnMissingBean(OrchestrationSettings::class)]
    public function orchestrationSettings(Config $config): OrchestrationSettings
    {
        return OrchestrationSettings::fromConfig($config);
    }

    #[Bean]
    #[Lazy]
    #[ConditionalOnMissingBean(Clock::class)]
    public function orchestrationClock(): Clock
    {
        return new SystemClock;
    }

    #[Bean]
    #[Lazy]
    #[ConditionalOnMissingBean(ExecutionPersistenceProvider::class)]
    public function executionPersistenceProvider(OrchestrationSettings $settings, Container $app): ExecutionPersistenceProvider
    {
        $persistence = $settings->persistence;

        return match ($persistence->provider) {
            'cache' => new CacheExecutionPersistenceProvider(self::cacheStore($app, $persistence->cacheStore), $persistence->keyPrefix, $persistence->keyTtl),
            'database' => new DatabaseExecutionPersistenceProvider(self::connection($app, $persistence->connection, 'firefly.orchestration.persistence.provider')),
            'event-sourced' => throw new ConfigurationException('Configuration key [firefly.orchestration.persistence.provider] is event-sourced, which firefly/eventsourcing supplies; install it, or choose memory, cache or database.'),
            default => new MemoryExecutionPersistenceProvider,
        };
    }

    #[Bean]
    #[Lazy]
    #[ConditionalOnMissingBean(DeadLetterStore::class)]
    public function orchestrationDeadLetterStore(OrchestrationSettings $settings, Container $app): DeadLetterStore
    {
        return $settings->dlq->store === 'database'
            ? new DatabaseDeadLetterStore(self::connection($app, $settings->persistence->connection, 'firefly.orchestration.dlq.store'))
            : new MemoryDeadLetterStore;
    }

    #[Bean]
    #[Lazy]
    #[ConditionalOnMissingBean(OrchestrationMetrics::class)]
    public function orchestrationMetrics(): OrchestrationMetrics
    {
        return new NoOpOrchestrationMetrics;
    }

    #[Bean]
    #[Lazy]
    #[ConditionalOnMissingBean(OrchestrationTracing::class)]
    public function orchestrationTracing(): OrchestrationTracing
    {
        return new NoOpOrchestrationTracing;
    }

    #[Bean]
    #[Lazy]
    #[ConditionalOnMissingBean(CompositeOrchestrationEvents::class)]
    public function orchestrationEvents(OrchestrationSettings $settings, OrchestrationMetrics $metrics, FireflyContainer $beans, Container $app): CompositeOrchestrationEvents
    {
        $logger = self::logger($app);
        $listeners = [new LoggerOrchestrationEvents($logger)];
        if ($settings->metricsEnabled) {
            $listeners[] = new MetricsOrchestrationEvents($metrics);
        }

        return new CompositeOrchestrationEvents($listeners, static fn (): array => $beans->getAll(OrchestrationEvents::class), $logger);
    }

    #[Bean]
    #[Lazy]
    #[ConditionalOnMissingBean(OrchestrationEventPublisher::class)]
    public function orchestrationEventPublisher(OrchestrationSettings $settings, EventPublisher $publisher): OrchestrationEventPublisher
    {
        return $settings->events->enabled ? new EdaOrchestrationEventPublisher($publisher, $settings->events->defaultTopic) : new NoOpOrchestrationEventPublisher;
    }

    #[Bean]
    #[Lazy]
    #[ConditionalOnMissingBean(ExecutionLock::class)]
    public function executionLock(OrchestrationSettings $settings, DistributedLock $lock): ExecutionLock
    {
        return new ExecutionLock($lock, $settings->lock->ttl, $settings->lock->wait);
    }

    #[Bean]
    #[Lazy]
    #[ConditionalOnMissingBean(ExecutionAdmission::class)]
    public function executionAdmission(OrchestrationSettings $settings, ExecutionPersistenceProvider $persistence, ResilienceStore $store): ExecutionAdmission
    {
        $backpressure = $settings->backpressure;
        $breaker = $backpressure->strategy === 'circuit-breaker'
            ? new CircuitBreaker(
                key: 'firefly:orchestration:backpressure',
                store: $store,
                failureThreshold: $backpressure->failureThreshold,
                waitDurationInOpen: $backpressure->recoveryTimeout,
                halfOpenMaxCalls: $backpressure->halfOpenMaxCalls,
            )
            : null;

        return new ExecutionAdmission($persistence, $backpressure, $breaker);
    }

    #[Bean]
    #[Lazy]
    #[ConditionalOnMissingBean(DeadLetterService::class)]
    public function deadLetterService(OrchestrationSettings $settings, DeadLetterStore $store, CompositeOrchestrationEvents $events): DeadLetterService
    {
        return new DeadLetterService($store, $events, $settings->dlq->enabled);
    }

    #[Bean]
    #[Lazy]
    #[ConditionalOnMissingBean(CallbackInvoker::class)]
    public function callbackInvoker(Container $app): CallbackInvoker
    {
        return new CallbackInvoker($app, self::logger($app));
    }

    #[Bean]
    #[Lazy]
    #[ConditionalOnMissingBean(EngineSupport::class)]
    public function engineSupport(
        OrchestrationSettings $settings,
        ExecutionPersistenceProvider $persistence,
        DeadLetterService $deadLetters,
        CompositeOrchestrationEvents $events,
        OrchestrationEventPublisher $lifecycle,
        EventPublisher $eda,
        OrchestrationTracing $tracing,
        CallbackInvoker $callbacks,
        Clock $clock,
        ExecutionLock $lock,
        ExecutionAdmission $admission,
        Container $app,
    ): EngineSupport {
        return new EngineSupport(
            persistence: $persistence,
            deadLetters: $deadLetters,
            events: $events,
            lifecycle: $lifecycle,
            stepEvents: new EdaOrchestrationEventPublisher($eda, $settings->events->defaultTopic),
            tracing: $settings->tracingEnabled ? $tracing : new NoOpOrchestrationTracing,
            callbacks: $callbacks,
            clock: $clock,
            lock: $lock,
            admission: $admission,
            logger: self::logger($app),
        );
    }

    #[Bean]
    #[Lazy]
    #[ConditionalOnMissingBean(ConditionEvaluator::class)]
    public function conditionEvaluator(): ConditionEvaluator
    {
        return new ConditionEvaluator;
    }

    #[Bean]
    #[Lazy]
    #[ConditionalOnMissingBean(InvocationRunner::class)]
    public function invocationRunner(Container $app): InvocationRunner
    {
        return new InvocationRunner($app, new ArgumentResolver);
    }

    #[Bean]
    #[Lazy]
    #[ConditionalOnMissingBean(StepInvoker::class)]
    public function stepInvoker(InvocationRunner $runner, EngineSupport $support): StepInvoker
    {
        return new StepInvoker($runner, $support->tracing);
    }

    #[Bean]
    #[Lazy]
    #[ConditionalOnMissingBean(SagaRegistry::class)]
    public function sagaRegistry(): SagaRegistry
    {
        return new SagaRegistry;
    }

    #[Bean]
    #[Lazy]
    #[ConditionalOnMissingBean(TccRegistry::class)]
    public function tccRegistry(): TccRegistry
    {
        return new TccRegistry;
    }

    #[Bean]
    #[Lazy]
    #[ConditionalOnMissingBean(WorkflowRegistry::class)]
    public function workflowRegistry(): WorkflowRegistry
    {
        return new WorkflowRegistry;
    }

    #[Bean]
    #[Lazy]
    #[ConditionalOnMissingBean(EventGateway::class)]
    public function eventGateway(): EventGateway
    {
        return new EventGateway;
    }

    #[Bean]
    #[Lazy]
    #[ConditionalOnMissingBean(CompensationErrorHandlerFactory::class)]
    public function compensationErrorHandlerFactory(Container $app): CompensationErrorHandlerFactory
    {
        return new CompensationErrorHandlerFactory($app, self::logger($app));
    }

    #[Bean]
    #[Lazy]
    #[ConditionalOnMissingBean(SagaCompensator::class)]
    public function sagaCompensator(OrchestrationSettings $settings, StepInvoker $invoker, EngineSupport $support, CompensationErrorHandlerFactory $handlers, ResilienceRegistry $resilience, ResilienceStore $store): SagaCompensator
    {
        return new SagaCompensator($invoker, $support, $settings->saga->compensationPolicy, $handlers->get($settings->saga->compensationErrorHandler), $resilience, $store);
    }

    #[Bean]
    #[Lazy]
    #[ConditionalOnMissingBean(SagaEngine::class)]
    public function sagaEngine(OrchestrationSettings $settings, SagaRegistry $registry, StepInvoker $invoker, SagaCompensator $compensator, EngineSupport $support): SagaEngine
    {
        return new SagaEngine($registry, new SagaExecutionOrchestrator($invoker, $support), $compensator, $support, $settings->saga);
    }

    #[Bean]
    #[Lazy]
    #[ConditionalOnMissingBean(SagaCompositor::class)]
    public function sagaCompositor(SagaEngine $engine, EngineSupport $support, ConditionEvaluator $conditions): SagaCompositor
    {
        return new SagaCompositor($engine, $support->events, $conditions, new CompositionDataFlowManager);
    }

    #[Bean]
    #[Lazy]
    #[ConditionalOnMissingBean(TccEngine::class)]
    public function tccEngine(OrchestrationSettings $settings, TccRegistry $registry, StepInvoker $invoker, EngineSupport $support): TccEngine
    {
        return new TccEngine($registry, new TccExecutionOrchestrator($invoker, $support, $settings->tcc), $support, $settings->tcc);
    }

    #[Bean]
    #[Lazy]
    #[ConditionalOnMissingBean(TccCompositor::class)]
    public function tccCompositor(OrchestrationSettings $settings, TccEngine $engine, EngineSupport $support, ConditionEvaluator $conditions, ResilienceRegistry $resilience, ResilienceStore $store): TccCompositor
    {
        // Task 12 fix: the compositor honours tcc.composition.compensation-policy and needs the resilience
        // registry and store for retry-with-backoff / circuit-breaker, exactly as SagaCompensator does.
        return new TccCompositor($engine, $support->events, $conditions, new CompositionDataFlowManager, $settings->tcc, $resilience, $store);
    }

    #[Bean]
    #[Lazy]
    #[ConditionalOnMissingBean(WorkflowDispatcher::class)]
    public function workflowDispatcher(OrchestrationSettings $settings, Dispatcher $bus): WorkflowDispatcher
    {
        return new BusWorkflowDispatcher($bus, $settings->workflow->queueConnection, $settings->workflow->queueName);
    }

    #[Bean]
    #[Lazy]
    #[ConditionalOnMissingBean(WorkflowEngine::class)]
    public function workflowEngine(OrchestrationSettings $settings, WorkflowRegistry $registry, StepInvoker $invoker, EngineSupport $support, ConditionEvaluator $conditions, WorkflowDispatcher $dispatcher, Container $app): WorkflowEngine
    {
        return new WorkflowEngine(
            $registry,
            new WorkflowExecutor($invoker, $support, $conditions, new WaitEvaluator($support->events)),
            $support,
            $settings->workflow,
            $dispatcher,
            $app,
            $settings->backpressure->batchSize,
        );
    }

    #[Bean]
    #[Lazy]
    #[ConditionalOnMissingBean(Orchestrator::class)]
    public function orchestrator(SagaEngine $sagas, TccEngine $tccs, WorkflowEngine $workflows, ExecutionPersistenceProvider $persistence): Orchestrator
    {
        return new Orchestrator($sagas, $tccs, $workflows, $persistence);
    }

    #[Bean]
    #[Lazy]
    #[ConditionalOnMissingBean(DeadLetterRetrier::class)]
    public function deadLetterRetrier(DeadLetterService $deadLetters, Orchestrator $orchestrator): DeadLetterRetrier
    {
        return new DeadLetterRetrier($deadLetters, $orchestrator);
    }

    #[Bean]
    #[Lazy]
    #[ConditionalOnMissingBean(RecoveryService::class)]
    public function recoveryService(OrchestrationSettings $settings, ExecutionPersistenceProvider $persistence, SagaEngine $sagas, TccEngine $tccs, WorkflowEngine $workflows, Clock $clock, Container $app): RecoveryService
    {
        return new RecoveryService($persistence, $sagas, $tccs, $workflows, $settings->recovery, $settings->persistence, $clock, self::logger($app), $settings->backpressure->batchSize);
    }

    private static function cacheStore(Container $app, ?string $store): CacheRepository
    {
        if (! $app->bound(CacheFactory::class)) {
            throw new ConfigurationException('Configuration key [firefly.orchestration.persistence.provider] is cache but no cache manager is bound.');
        }

        /** @var CacheFactory $cache */
        $cache = $app->make(CacheFactory::class);

        return $cache->store($store);
    }

    private static function connection(Container $app, ?string $connection, string $key): ConnectionInterface
    {
        $databases = $app->bound('db') ? $app->make('db') : null;
        if (! $databases instanceof ConnectionResolverInterface) {
            throw new ConfigurationException("Configuration key [{$key}] is database but no database connection resolver (db) is bound.");
        }

        return $databases->connection($connection);
    }

    private static function logger(Container $app): LoggerInterface
    {
        $logger = $app->bound(LoggerInterface::class) ? $app->make(LoggerInterface::class) : null;

        return $logger instanceof LoggerInterface ? $logger : new NullLogger;
    }
}
