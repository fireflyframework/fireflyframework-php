<?php

declare(strict_types=1);

namespace Firefly\Orchestration;

use Firefly\Config\Config;
use Firefly\Kernel\Exception\Framework\ConfigurationException;
use Firefly\Orchestration\Core\CompensationPolicy;
use Firefly\Orchestration\Settings\BackpressureSettings;
use Firefly\Orchestration\Settings\DlqSettings;
use Firefly\Orchestration\Settings\DurationReader;
use Firefly\Orchestration\Settings\EventsSettings;
use Firefly\Orchestration\Settings\LockSettings;
use Firefly\Orchestration\Settings\PersistenceSettings;
use Firefly\Orchestration\Settings\RecoverySettings;
use Firefly\Orchestration\Settings\RestSettings;
use Firefly\Orchestration\Settings\SagaSettings;
use Firefly\Orchestration\Settings\TccSettings;
use Firefly\Orchestration\Settings\WorkflowSettings;
use Illuminate\Config\Repository;

/**
 * firefly.orchestration.*, read once (jafly OrchestrationProperties, minus what has no meaning in a
 * synchronous PHP process — spec decision D20). Every key is spelled in full where it is read, so the
 * reference guard sees it; every value it cannot honour refuses the boot here, naming the key, instead of
 * surfacing as a strange execution later.
 */
final readonly class OrchestrationSettings
{
    /** @var list<string> */
    public const array PERSISTENCE_PROVIDERS = ['memory', 'cache', 'database', 'event-sourced'];

    /** @var list<string> */
    public const array DLQ_STORES = ['memory', 'database'];

    /** @var list<string> */
    public const array RECOVERY_ACTIONS = [RecoverySettings::REPORT, RecoverySettings::RESUME, RecoverySettings::COMPENSATE];

    /** @var list<string> */
    public const array BACKPRESSURE_STRATEGIES = ['none', 'circuit-breaker'];

    public function __construct(
        public SagaSettings $saga,
        public TccSettings $tcc,
        public WorkflowSettings $workflow,
        public PersistenceSettings $persistence,
        public RecoverySettings $recovery,
        public DlqSettings $dlq,
        public EventsSettings $events,
        public RestSettings $rest,
        public BackpressureSettings $backpressure,
        public LockSettings $lock,
        public bool $healthEnabled = true,
        public bool $metricsEnabled = true,
        public bool $tracingEnabled = true,
        public bool $validationEnabled = true,
        public bool $failOnWarning = false,
    ) {}

    public static function defaults(): self
    {
        return self::fromConfig(new Config(new Repository([])));
    }

    /** A duration in seconds as the string a ScheduledDescriptor takes: 3600.0 → '3600s', 0.5 → '0.5s'. */
    public static function interval(float $seconds): string
    {
        return $seconds.'s';
    }

    public static function fromConfig(Config $config): self
    {
        $durations = new DurationReader($config);

        $saga = new SagaSettings(
            enabled: $config->bool('firefly.orchestration.saga.enabled', true),
            compensationPolicy: CompensationPolicy::fromConfig($config->string('firefly.orchestration.saga.compensation-policy', 'strict-sequential'), 'firefly.orchestration.saga.compensation-policy'),
            defaultTimeout: $durations->get('firefly.orchestration.saga.default-timeout', 300.0),
            compensationErrorHandler: self::nonEmpty($config->string('firefly.orchestration.saga.compensation-error-handler', 'default'), 'firefly.orchestration.saga.compensation-error-handler'),
        );

        $tcc = new TccSettings(
            enabled: $config->bool('firefly.orchestration.tcc.enabled', true),
            defaultTimeout: $durations->get('firefly.orchestration.tcc.default-timeout', 30.0),
            compositionEnabled: $config->bool('firefly.orchestration.tcc.composition.enabled', true),
            compositionCompensationPolicy: CompensationPolicy::fromConfig($config->string('firefly.orchestration.tcc.composition.compensation-policy', 'strict-sequential'), 'firefly.orchestration.tcc.composition.compensation-policy'),
        );

        $workflow = new WorkflowSettings(
            enabled: $config->bool('firefly.orchestration.workflow.enabled', true),
            queueConnection: self::nullableName($config->get('firefly.orchestration.workflow.queue.connection'), 'firefly.orchestration.workflow.queue.connection'),
            queueName: self::nullableName($config->get('firefly.orchestration.workflow.queue.name'), 'firefly.orchestration.workflow.queue.name'),
            tickEnabled: $config->bool('firefly.orchestration.workflow.tick.enabled', true),
            tickInterval: $durations->get('firefly.orchestration.workflow.tick.interval', 60.0, 'a zero tick interval would schedule the timer sweep continuously'),
            maxResumeAttempts: self::atLeast($config->int('firefly.orchestration.workflow.max-resume-attempts', 5), 1, 'firefly.orchestration.workflow.max-resume-attempts'),
        );

        $provider = $config->string('firefly.orchestration.persistence.provider', 'memory');
        if (! in_array($provider, self::PERSISTENCE_PROVIDERS, true)) {
            throw new ConfigurationException("Configuration key [firefly.orchestration.persistence.provider] must be one of memory, cache, database or event-sourced, got [{$provider}].");
        }
        $keyTtl = $durations->get('firefly.orchestration.persistence.key-ttl', 0.0);
        $persistence = new PersistenceSettings(
            provider: $provider,
            connection: self::nullableName($config->get('firefly.orchestration.persistence.connection'), 'firefly.orchestration.persistence.connection'),
            cacheStore: self::nullableName($config->get('firefly.orchestration.persistence.cache-store'), 'firefly.orchestration.persistence.cache-store'),
            keyPrefix: $config->string('firefly.orchestration.persistence.key-prefix', 'orchestration:'),
            keyTtl: $keyTtl > 0.0 ? (int) ceil($keyTtl) : null,
            retentionPeriod: $durations->get('firefly.orchestration.persistence.retention-period', 604800.0, 'a zero retention period would delete every execution the moment it ends'),
            cleanupInterval: $durations->get('firefly.orchestration.persistence.cleanup-interval', 3600.0, 'a zero cleanup interval would run the recovery sweep continuously'),
        );

        $action = $config->string('firefly.orchestration.recovery.action', RecoverySettings::REPORT);
        if (! in_array($action, self::RECOVERY_ACTIONS, true)) {
            throw new ConfigurationException("Configuration key [firefly.orchestration.recovery.action] must be report, resume or compensate, got [{$action}].");
        }
        $recovery = new RecoverySettings(
            enabled: $config->bool('firefly.orchestration.recovery.enabled', true),
            staleThreshold: $durations->get('firefly.orchestration.recovery.stale-threshold', 3600.0),
            action: $action,
        );

        $store = $config->string('firefly.orchestration.dlq.store', 'memory');
        if (! in_array($store, self::DLQ_STORES, true)) {
            throw new ConfigurationException("Configuration key [firefly.orchestration.dlq.store] must be memory or database, got [{$store}].");
        }

        $path = '/'.trim($config->string('firefly.orchestration.rest.path', '/orchestration'), '/');
        if ($path === '/') {
            throw new ConfigurationException('Configuration key [firefly.orchestration.rest.path] must name a path such as /orchestration, not the application root.');
        }

        $strategy = $config->string('firefly.orchestration.backpressure.strategy', 'none');
        if (! in_array($strategy, self::BACKPRESSURE_STRATEGIES, true)) {
            throw new ConfigurationException("Configuration key [firefly.orchestration.backpressure.strategy] must be none or circuit-breaker, got [{$strategy}]. jafly's adaptive and batched strategies schedule reactive streams and have no meaning in a synchronous PHP process; use max-in-flight and batch-size instead.");
        }

        return new self(
            saga: $saga,
            tcc: $tcc,
            workflow: $workflow,
            persistence: $persistence,
            recovery: $recovery,
            dlq: new DlqSettings($config->bool('firefly.orchestration.dlq.enabled', true), $store),
            events: new EventsSettings(
                enabled: $config->bool('firefly.orchestration.events.enabled', false),
                defaultTopic: $config->string('firefly.orchestration.events.default-topic', ''),
                gatewayEnabled: $config->bool('firefly.orchestration.event-gateway.enabled', true),
            ),
            rest: new RestSettings($config->bool('firefly.orchestration.rest.enabled', false), $path),
            backpressure: new BackpressureSettings(
                strategy: $strategy,
                batchSize: self::atLeast($config->int('firefly.orchestration.backpressure.batch-size', 10), 1, 'firefly.orchestration.backpressure.batch-size'),
                maxInFlight: self::atLeast($config->int('firefly.orchestration.backpressure.max-in-flight', 0), 0, 'firefly.orchestration.backpressure.max-in-flight'),
                failureThreshold: self::atLeast($config->int('firefly.orchestration.backpressure.circuit-breaker.failure-threshold', 5), 1, 'firefly.orchestration.backpressure.circuit-breaker.failure-threshold'),
                recoveryTimeout: $durations->get('firefly.orchestration.backpressure.circuit-breaker.recovery-timeout', 30.0),
                halfOpenMaxCalls: self::atLeast($config->int('firefly.orchestration.backpressure.circuit-breaker.half-open-max-calls', 3), 1, 'firefly.orchestration.backpressure.circuit-breaker.half-open-max-calls'),
            ),
            lock: new LockSettings(
                ttl: $durations->get('firefly.orchestration.lock.ttl', 300.0, 'a zero lock lifetime would let a second process in immediately'),
                wait: $durations->get('firefly.orchestration.lock.wait', 5.0),
            ),
            healthEnabled: $config->bool('firefly.orchestration.health.enabled', true),
            metricsEnabled: $config->bool('firefly.orchestration.metrics.enabled', true),
            tracingEnabled: $config->bool('firefly.orchestration.tracing.enabled', true),
            validationEnabled: $config->bool('firefly.orchestration.validation.enabled', true),
            failOnWarning: $config->bool('firefly.orchestration.validation.fail-on-warning', false),
        );
    }

    private static function nonEmpty(string $value, string $key): string
    {
        if (trim($value) === '') {
            throw new ConfigurationException("Configuration key [{$key}] must not be empty.");
        }

        return $value;
    }

    private static function nullableName(mixed $value, string $key): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }
        if (! is_string($value)) {
            throw new ConfigurationException("Configuration key [{$key}] must be a name (or null for the default), got ".get_debug_type($value).'.');
        }

        return $value;
    }

    private static function atLeast(int $value, int $minimum, string $key): int
    {
        if ($value < $minimum) {
            throw new ConfigurationException("Configuration key [{$key}] must be at least {$minimum}, got [{$value}].");
        }

        return $value;
    }
}
