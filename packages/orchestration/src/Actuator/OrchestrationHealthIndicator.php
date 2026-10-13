<?php

declare(strict_types=1);

namespace Firefly\Orchestration\Actuator;

use Firefly\Actuator\Health\ConditionalHealthIndicator;
use Firefly\Actuator\Health\Health;
use Firefly\Container\Attributes\Component;
use Firefly\Container\Container as FireflyContainer;
use Firefly\Orchestration\Dlq\DeadLetterService;
use Firefly\Orchestration\OrchestrationSettings;
use Firefly\Orchestration\Persistence\ExecutionPersistenceProvider;
use Firefly\Orchestration\Recovery\RecoveryService;
use Throwable;

/**
 * The `orchestration` health component (HealthContributorRegistrar names it after the class). DOWN when the
 * execution store does not answer — every engine checkpoints through it — UP otherwise, with the open
 * executions, the stale ones the next recovery sweep will see, and the dead letters waiting for an operator.
 * Absent while `firefly.orchestration.health.enabled` is false.
 */
#[Component]
final class OrchestrationHealthIndicator implements ConditionalHealthIndicator
{
    public function __construct(private readonly FireflyContainer $beans) {}

    public function available(): bool
    {
        if (! $this->beans->has(OrchestrationSettings::class)) {
            return false;
        }

        $settings = $this->beans->get(OrchestrationSettings::class);

        return $settings instanceof OrchestrationSettings && $settings->healthEnabled;
    }

    public function health(): Health
    {
        /** @var OrchestrationSettings $settings */
        $settings = $this->beans->get(OrchestrationSettings::class);
        /** @var ExecutionPersistenceProvider $persistence */
        $persistence = $this->beans->get(ExecutionPersistenceProvider::class);

        if (! $persistence->isHealthy()) {
            return Health::down(['provider' => $settings->persistence->provider, 'error' => 'the execution store does not answer']);
        }

        try {
            /** @var RecoveryService $recovery */
            $recovery = $this->beans->get(RecoveryService::class);
            /** @var DeadLetterService $deadLetters */
            $deadLetters = $this->beans->get(DeadLetterService::class);

            return Health::up([
                'provider' => $settings->persistence->provider,
                'inFlight' => $persistence->countInFlight(),
                'stale' => $settings->recovery->enabled ? count($recovery->findStale()) : 0,
                'deadLetters' => $deadLetters->count(),
            ]);
        } catch (Throwable $error) {
            return Health::down(['provider' => $settings->persistence->provider, 'error' => $error->getMessage()]);
        }
    }
}
