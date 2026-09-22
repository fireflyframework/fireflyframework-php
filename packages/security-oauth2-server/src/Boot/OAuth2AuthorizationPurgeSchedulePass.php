<?php

declare(strict_types=1);

namespace Firefly\Security\OAuth2\Server\Boot;

use Firefly\Context\Boot\BootContext;
use Firefly\Context\Boot\BootPass;
use Firefly\Context\Boot\BootPhase;
use Firefly\Scheduling\Schedule\ScheduledDescriptor;
use Firefly\Scheduling\Schedule\ScheduledManifest;
use Firefly\Security\OAuth2\Server\Authorization\OAuth2AuthorizationPurgeTask;

/**
 * Puts the purge on the framework's schedule by contributing ONE ScheduledDescriptor to the bound
 * ScheduledManifest — the same row `firefly:cache` would compile for a #[Scheduled(cron:, lock:)] method — so
 * ScheduleWiringPass wires it onto Laravel's Schedule under the DistributedLock, `firefly:schedule` lists it,
 * and the actuator and the admin dashboard show it.
 *
 * WHY THIS PHASE. The manifest is bound at register() time (SchedulingWiringProvider, or firefly/cli's cache
 * provider with an unconditional instance()), and ScheduledTasksEndpoint — an eager singleton — captures it by
 * constructor at EagerSingletons (900). Extending it must therefore happen after every provider registered and
 * before 900: InfrastructureStart (850) is the last instance-stage phase before eager resolution, and order 10
 * keeps it after the lifecycle starts of that phase. Rebinding the instance (make, extend, instance) is the one
 * approach that survives both binding styles; Container::extend() does not reach an instance() bound after it.
 * Nothing happens when scheduling is not installed (no manifest bound) or the purge is off.
 *
 * The three keys are read spelled out in full, never through AuthorizationServerSettings::PREFIX, for the reason
 * that class documents: tests/ConfigReferenceTest.php finds the keys the framework reads by their literal
 * `'firefly.…'` string, and an interpolated key is one the reference guard could no longer see.
 */
final class OAuth2AuthorizationPurgeSchedulePass implements BootPass
{
    public function phase(): BootPhase
    {
        return BootPhase::InfrastructureStart;
    }

    public function order(): int
    {
        return 10;
    }

    public function run(BootContext $context): void
    {
        $config = $context->config;
        if (! $config->bool('firefly.security.enabled', false)
            || ! $config->bool('firefly.security.oauth2.server.enabled', false)
            || ! $config->bool('firefly.security.oauth2.server.authorizations.purge.enabled', false)) {
            return;
        }

        $container = $context->container;
        if (! class_exists(ScheduledManifest::class) || ! $container->bound(ScheduledManifest::class)) {
            return;
        }

        /** @var ScheduledManifest $manifest */
        $manifest = $container->make(ScheduledManifest::class);
        $container->instance(ScheduledManifest::class, new ScheduledManifest([
            ...$manifest->all(),
            new ScheduledDescriptor(
                class: OAuth2AuthorizationPurgeTask::class,
                method: 'purge',
                cron: $config->string('firefly.security.oauth2.server.authorizations.purge.cron', '*/15 * * * *'),
                lockName: OAuth2AuthorizationPurgeTask::LOCK,
                lockTtl: '300s',
            ),
        ]));
    }
}
