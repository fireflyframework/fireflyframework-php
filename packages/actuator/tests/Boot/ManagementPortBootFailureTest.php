<?php

declare(strict_types=1);

use Firefly\Actuator\ActuatorServiceProvider;
use Firefly\Actuator\ActuatorWiringProvider;
use Firefly\Actuator\Server\ManagementPortGuard;
use Firefly\Actuator\Server\ManagementServerSettings;
use Firefly\Context\Boot\ApplicationContext;
use Firefly\Kernel\Exception\Framework\ConfigurationException;
use Firefly\Scheduling\Schedule\ScheduledManifest;
use Firefly\Web\Route\RouteManifest;
use Illuminate\Foundation\Application;
use Illuminate\Http\Request;

/**
 * The boot-time half of the feature, exercised through the REAL provider stack rather than a hand-built BootContext
 * (ActuatorRouteRegistrarTest covers the pass in isolation): the #[Bean]s really are registered, really are
 * resolvable, and a management port equal to the application port really does abort a whole application boot rather
 * than producing a container whose guard permits everything.
 *
 * Same bare-skeleton shape as PackageBootTest — RouteManifest/ScheduledManifest are stubbed in rather than dragging
 * the Web/Scheduling boot pipelines in behind them.
 *
 * @param  array<string, mixed>  $firefly
 */
function bootActuatorWithManagement(array $firefly): Application
{
    return fireflyApplication(
        config: ['firefly' => $firefly],
        providers: [ActuatorServiceProvider::class, ActuatorWiringProvider::class],
        bindings: [
            RouteManifest::class => new RouteManifest([]),
            ScheduledManifest::class => new ScheduledManifest([]),
        ],
    );
}

it('binds the management settings and guard as resolvable beans', function () {
    $app = bootActuatorWithManagement(['management' => ['enabled' => true, 'server' => ['port' => 9001]]]);

    /** @var ManagementServerSettings $settings */
    $settings = $app->make(ManagementServerSettings::class);

    expect($app->make(ApplicationContext::class))->toBeInstanceOf(ApplicationContext::class)
        ->and($settings->port)->toBe(9001)
        ->and($settings->isSeparate())->toBeTrue()
        ->and($app->make(ManagementPortGuard::class))->toBeInstanceOf(ManagementPortGuard::class);
});

it('aborts the whole boot when the management port is the application port', function () {
    expect(fn () => bootActuatorWithManagement([
        'management' => ['enabled' => true, 'server' => ['port' => 8000]],
        'server' => ['port' => 8000],
    ]))->toThrow(ConfigurationException::class, 'firefly.management.server.port (8000) is the application port');
});

// An unset management port must leave the beans present and inert, so nothing about a default application changes.
it('binds an inert guard when no management port is configured', function () {
    $app = bootActuatorWithManagement(['management' => ['enabled' => true]]);

    /** @var ManagementServerSettings $settings */
    $settings = $app->make(ManagementServerSettings::class);

    expect($settings->port)->toBeNull()
        ->and($settings->isSeparate())->toBeFalse()
        ->and($app->make(ManagementPortGuard::class)->permits(Request::create('http://localhost:1/x')))
        ->toBeTrue();
});
