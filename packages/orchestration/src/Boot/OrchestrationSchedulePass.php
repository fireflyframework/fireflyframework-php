<?php

declare(strict_types=1);

namespace Firefly\Orchestration\Boot;

use Firefly\Context\Boot\BootContext;
use Firefly\Context\Boot\BootPass;
use Firefly\Context\Boot\BootPhase;
use Firefly\Orchestration\Manifest\OrchestrationManifest;
use Firefly\Orchestration\OrchestrationSettings;
use Firefly\Orchestration\Recovery\OrchestrationRecoveryTask;
use Firefly\Orchestration\Recovery\OrchestrationTickTask;
use Firefly\Scheduling\Schedule\ScheduledDescriptor;
use Firefly\Scheduling\Schedule\ScheduledManifest;

/**
 * Puts the tick and the recovery sweep on the framework's schedule by contributing ScheduledDescriptors to
 * the bound ScheduledManifest — the OAuth2AuthorizationPurgeSchedulePass precedent, and for its reason: the
 * ScheduleWiringPass then runs them under the DistributedLock, `firefly:schedule` lists them and the actuator
 * shows them. InfrastructureStart/20 is after every provider registered and before EagerSingletons (900)
 * captures the manifest.
 *
 * Only where they can do something (spec decision D21): the tick when `workflow.tick.enabled` and the
 * manifest declares a workflow or persistence is not `memory`; the sweep when `recovery.enabled` and
 * persistence is not `memory`.
 */
final class OrchestrationSchedulePass implements BootPass
{
    public function phase(): BootPhase
    {
        return BootPhase::InfrastructureStart;
    }

    public function order(): int
    {
        return 20;
    }

    public function run(BootContext $context): void
    {
        $container = $context->container;
        if (! class_exists(ScheduledManifest::class) || ! $container->bound(ScheduledManifest::class) || ! $container->bound(OrchestrationSettings::class)) {
            return;
        }

        /** @var OrchestrationSettings $settings */
        $settings = $container->make(OrchestrationSettings::class);
        $durable = $settings->persistence->provider !== 'memory';
        $declaresWorkflows = $container->bound(OrchestrationManifest::class) && $this->declaresWorkflows($container->make(OrchestrationManifest::class));

        $descriptors = [];
        if ($settings->workflow->enabled && $settings->workflow->tickEnabled && ($durable || $declaresWorkflows)) {
            $descriptors[] = new ScheduledDescriptor(
                class: OrchestrationTickTask::class,
                method: 'tick',
                fixedRate: OrchestrationSettings::interval($settings->workflow->tickInterval),
                lockName: OrchestrationTickTask::LOCK,
                lockTtl: '300s',
            );
        }
        if ($settings->recovery->enabled && $durable) {
            $descriptors[] = new ScheduledDescriptor(
                class: OrchestrationRecoveryTask::class,
                method: 'recover',
                fixedRate: OrchestrationSettings::interval($settings->persistence->cleanupInterval),
                lockName: OrchestrationRecoveryTask::LOCK,
                lockTtl: '600s',
            );
        }
        if ($descriptors === []) {
            return;
        }

        /** @var ScheduledManifest $manifest */
        $manifest = $container->make(ScheduledManifest::class);
        $container->instance(ScheduledManifest::class, new ScheduledManifest([...$manifest->all(), ...$descriptors]));
    }

    private function declaresWorkflows(mixed $manifest): bool
    {
        return $manifest instanceof OrchestrationManifest && $manifest->toArray()['workflows'] !== [];
    }
}
