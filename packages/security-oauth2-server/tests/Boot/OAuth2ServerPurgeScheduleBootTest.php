<?php

declare(strict_types=1);

use Firefly\Actuator\ActuatorServiceProvider;
use Firefly\Actuator\ActuatorWiringProvider;
use Firefly\Actuator\Endpoint\EndpointRequest;
use Firefly\Actuator\Introspection\ScheduledTasksEndpoint;
use Firefly\Resilience\ResilienceServiceProvider;
use Firefly\Scheduling\Schedule\ScheduledManifest;
use Firefly\Scheduling\SchedulingServiceProvider;
use Firefly\Scheduling\SchedulingWiringProvider;
use Firefly\Security\OAuth2\Server\Authorization\InMemoryOAuth2AuthorizationConsentService;
use Firefly\Security\OAuth2\Server\Authorization\InMemoryOAuth2AuthorizationService;
use Firefly\Security\OAuth2\Server\Authorization\OAuth2AuthorizationConsentService;
use Firefly\Security\OAuth2\Server\Authorization\OAuth2AuthorizationPurgeTask;
use Firefly\Security\OAuth2\Server\Authorization\OAuth2AuthorizationService;
use Firefly\Security\OAuth2\Server\Tests\Support\OAuth2ServerBootTestCase;
use Illuminate\Console\Scheduling\Schedule;

/**
 * The server over the real scheduling providers AND the actuator: ScheduledTasksEndpoint is the eager singleton
 * that captures the ScheduledManifest by constructor at EagerSingletons (900), so booting it here is what proves
 * the purge pass ran early enough (InfrastructureStart, 850) — a later phase would leave `/actuator/scheduledtasks`
 * blind to the purge while `firefly:schedule` still listed it. The db health indicator is off because this boot
 * registers no database.
 */
abstract class PurgeScheduleBootTestCase extends OAuth2ServerBootTestCase
{
    protected function fireflyProviders(): array
    {
        return [ResilienceServiceProvider::class, SchedulingServiceProvider::class, SchedulingWiringProvider::class, ...parent::fireflyProviders(), ActuatorServiceProvider::class, ActuatorWiringProvider::class];
    }

    protected function serverOverrides(): array
    {
        return [
            'cache.default' => 'array',
            'firefly.management.enabled' => true,
            'firefly.management.endpoint.health.db.enabled' => false,
            'firefly.scheduling.lock.provider' => 'cache',
            'firefly.security.oauth2.server.authorizations.purge.enabled' => true,
            'firefly.security.oauth2.server.authorizations.purge.cron' => '0 * * * *',
        ];
    }
}

uses(PurgeScheduleBootTestCase::class);

it('contributes the purge to the ScheduledManifest and to Laravel\'s Schedule, under the framework lock', function () {
    /** @var PurgeScheduleBootTestCase $this */
    $manifest = $this->app()->make(ScheduledManifest::class);
    $tasks = array_values(array_filter($manifest->all(), static fn ($task): bool => $task->class === OAuth2AuthorizationPurgeTask::class));

    expect($tasks)->toHaveCount(1)
        ->and($tasks[0]->method)->toBe('purge')
        ->and($tasks[0]->cron)->toBe('0 * * * *')
        ->and($tasks[0]->lockName)->toBe(OAuth2AuthorizationPurgeTask::LOCK)
        ->and($tasks[0]->lockTtl)->toBe('300s');

    $schedule = $this->app()->make(Schedule::class);
    $expressions = array_map(static fn ($event): string => $event->expression, $schedule->events());
    expect($expressions)->toContain('0 * * * *');
});

it('is listed by /actuator/scheduledtasks, whose endpoint captured the manifest as an eager singleton', function () {
    /** @var PurgeScheduleBootTestCase $this */
    $response = $this->app()->make(ScheduledTasksEndpoint::class)->handle(new EndpointRequest('GET', []));

    /** @var array{tasks: list<array{runnable: string, cron: string|null}>} $body */
    $body = $response->body;
    $runnables = array_column($body['tasks'], 'cron', 'runnable');

    expect($runnables)->toHaveKey(OAuth2AuthorizationPurgeTask::class.'@purge')
        ->and($runnables[OAuth2AuthorizationPurgeTask::class.'@purge'])->toBe('0 * * * *');
});

it('binds the memory drivers as the two ports and resolves the purge task the schedule will call', function () {
    /** @var PurgeScheduleBootTestCase $this */
    expect($this->app()->make(OAuth2AuthorizationService::class))->toBeInstanceOf(InMemoryOAuth2AuthorizationService::class)
        ->and($this->app()->make(OAuth2AuthorizationConsentService::class))->toBeInstanceOf(InMemoryOAuth2AuthorizationConsentService::class)
        ->and($this->app()->make(OAuth2AuthorizationPurgeTask::class))->toBeInstanceOf(OAuth2AuthorizationPurgeTask::class);
});
