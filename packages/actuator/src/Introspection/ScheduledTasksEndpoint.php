<?php

declare(strict_types=1);

namespace Firefly\Actuator\Introspection;

use Firefly\Actuator\Endpoint\ActuatorEndpoint;
use Firefly\Actuator\Endpoint\EndpointRequest;
use Firefly\Actuator\Endpoint\EndpointResponse;
use Firefly\Container\Attributes\Component;
use Firefly\Context\Condition\Attributes\ConditionalOnClass;
use Firefly\Scheduling\Schedule\ScheduledManifest;

/**
 * Lists the M7 #[Scheduled] manifest (the /scheduledtasks seam, a Spring-Boot-Actuator
 * ScheduledTasksEndpoint analog). Gated on firefly/scheduling being present
 * (#[ConditionalOnClass]) — an optional-dependency seam: firefly/actuator's own composer.json
 * requires firefly/scheduling today, so the condition always matches in THIS monorepo, but a future
 * decoupling of that hard require (or a consumer that vendors actuator without scheduling) then
 * degrades gracefully — this #[Component] simply never gets registered — instead of crashing boot on
 * an unresolvable ScheduledManifest constructor dependency. This attribute is genuinely evaluated,
 * not decorative: ActuatorServiceProvider extends AutoConfiguration, whose compiled ComponentManifest
 * AND ContextManifest (which independently captures class-level #[ConditionalOn*] attributes off ANY
 * class, per ContextScanner — see the sibling DbHealthIndicator entry in
 * cache/firefly-actuator-context.php) are merged by DefinitionAssembler into one BeanDefinitionRegistry
 * and filtered by ConditionPassTwoPass before ContainerRegistrar ever binds a class into the
 * container.
 *
 * When scheduling IS present but has compiled an EMPTY manifest (no #[Scheduled] methods anywhere in
 * the app), this endpoint still boots and simply answers `{"tasks": []}` — the OTHER sense of
 * "degrades gracefully", and the one actually exercisable in this monorepo (SchedulingWiringProvider
 * binds an empty ScheduledManifest by default).
 *
 * Return type is narrowed to the non-nullable EndpointResponse (same idiom as MappingsEndpoint):
 * /scheduledtasks has no sub-resource concept to 404 on.
 *
 * No #[Lazy] here: ScheduledManifest is bound eagerly by SchedulingWiringProvider::register()
 * (behind a bound() guard, defaulting to an empty manifest) — well before BootPhase::EagerSingletons
 * (900) runs. Verified directly against PackageBootTest: a plain (non-#[Lazy]) ScheduledTasksEndpoint
 * does not crash eager resolution.
 */
#[Component]
#[ConditionalOnClass('Firefly\\Scheduling\\Schedule\\ScheduledManifest')]
final class ScheduledTasksEndpoint implements ActuatorEndpoint
{
    public function __construct(private readonly ScheduledManifest $tasks) {}

    public function endpointId(): string
    {
        return 'scheduledtasks';
    }

    public function enabled(): bool
    {
        return true;
    }

    public function handle(EndpointRequest $request): EndpointResponse
    {
        $tasks = [];
        foreach ($this->tasks->all() as $task) {
            $tasks[] = [
                'runnable' => $task->class.'@'.$task->method,
                'cron' => $task->cron,
                'fixedRate' => $task->fixedRate,
                'fixedDelay' => $task->fixedDelay,
                'zone' => $task->zone,
            ];
        }

        return EndpointResponse::json(['tasks' => $tasks]);
    }
}
