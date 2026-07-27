<?php

declare(strict_types=1);

use Firefly\Actuator\ActuatorServiceProvider;
use Firefly\Actuator\ActuatorWiringProvider;
use Firefly\Actuator\Endpoint\ActuatorRegistry;
use Firefly\Actuator\Endpoint\ExposureModel;
use Firefly\Scheduling\Schedule\ScheduledManifest;
use Firefly\Validation\ValidationServiceProvider;
use Firefly\Web\WebServiceProvider;
use Illuminate\Contracts\Validation\Factory;
use Illuminate\Foundation\Application;
use Illuminate\Translation\ArrayLoader;
use Illuminate\Translation\Translator;
use Illuminate\Validation\Factory as IlluminateFactory;

/**
 * NOTE (brief-test fix, two gaps beyond the brief's literal draft): (1) ScheduledTasksEndpoint
 * (#[ConditionalOnClass(ScheduledManifest::class)]) survives condition filtering in THIS monorepo because
 * firefly/scheduling is a hard composer dependency of firefly/actuator (the class always autoloads), so it is
 * eagerly resolved at BootPhase::EagerSingletons (900) — its own docblock documents that this requires
 * ScheduledManifest to already be bound by then. Neither Scheduling provider is registered here (dragging in the
 * whole Scheduling boot pipeline is deliberately out of scope for an actuator-focused boot test — the same "stub
 * the cross-package seam" idiom PackageBootTest.php already established), so bind an empty manifest via the
 * `bindings:` menu.
 * (2) Illuminate\Contracts\Validation\Factory is bound EXPLICITLY (not via the harness's `needs: ['validation']`
 * menu) because that menu shortcuts straight to a Firefly\Validation\Validator INSTANCE, which would pre-empt
 * ValidationAutoConfiguration's own #[ConditionalOnMissingBean(Validator::class)] bean before ValidationServiceProvider
 * ever registers — defeating the whole point of THIS test (proving the REAL provider wires a real Validator). Bind
 * the raw Illuminate Factory instead, exactly as packages/validation/tests/ShippedProviderBootTest.php's own
 * real-provider boot does, and let ValidationAutoConfiguration's bean consume it. The HTTP kernel, however, IS
 * requested via `needs: ['http']` — WebServiceProvider's FilterChainRegistrar BootPass only needs SOME real
 * Illuminate\Contracts\Http\Kernel to read middleware off of, with no Firefly-side conditional bean in play, so the
 * harness's stock construction is behaviourally identical to binding it by hand.
 *
 * @param  array<string, mixed>  $management  the `firefly.management.*` tree for this boot
 */
function bootRealActuator(array $management = ['enabled' => true]): Application
{
    return fireflyApplication(
        config: ['firefly' => ['management' => $management], 'logging' => ['channels' => []]],
        providers: [ValidationServiceProvider::class, WebServiceProvider::class, ActuatorServiceProvider::class, ActuatorWiringProvider::class],
        bindings: [
            ScheduledManifest::class => new ScheduledManifest([]),
            Factory::class => new IlluminateFactory(new Translator(new ArrayLoader, 'en')),
        ],
        needs: ['http'],
    );
}

it('binds the ExposureModel from ActuatorAutoConfiguration', function () {
    expect(bootRealActuator()->make(ExposureModel::class))->toBeInstanceOf(ExposureModel::class);
});

it('populates the ActuatorRegistry with the built-in endpoints', function () {
    $registry = bootRealActuator()->make(ActuatorRegistry::class);

    expect($registry->ids())->toContain('health')->toContain('info')->toContain('env')
        ->toContain('beans')->toContain('conditions')->toContain('mappings')->toContain('loggers');
});
