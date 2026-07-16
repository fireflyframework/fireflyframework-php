<?php

declare(strict_types=1);

use Firefly\AutoConfigure\AutoConfigurationCollector;
use Firefly\AutoConfigure\FireflyAutoConfigureServiceProvider;
use Firefly\AutoConfigure\Pass\AutoConfigDiscoveryPass;
use Firefly\AutoConfigure\Pass\AutoConfigurationsPass;
use Firefly\Context\Boot\BootContext;
use Firefly\Context\Boot\FireflyKernel;
use Firefly\Context\Pass\ConditionPassOnePass;
use Firefly\Context\Pass\ConditionPassTwoPass;
use Firefly\Context\Pass\FlushDefinitionsPass;
use Firefly\Context\Pass\UserConfigurationsPass;
use Illuminate\Config\Repository;
use Illuminate\Foundation\Application;

/**
 * @param  array<string, mixed>  $firefly
 */
function bootstrapApp(array $firefly = []): Application
{
    $app = new Application;
    $app->instance('config', new Repository(['firefly' => $firefly]));

    return $app;
}

it('binds FireflyKernel + BootContext (bound()-guarded) at register() time', function () {
    $app = bootstrapApp();
    $app->register($provider = new FireflyAutoConfigureServiceProvider($app));

    expect($app->bound(FireflyKernel::class))->toBeTrue()
        ->and($app->make(FireflyKernel::class))->toBeInstanceOf(FireflyKernel::class)
        ->and($app->make(FireflyKernel::class)->context())->toBeInstanceOf(BootContext::class)
        ->and($app->bound(AutoConfigurationCollector::class))->toBeTrue();
});

it('contributes the full pass pipeline: the eight core passes + user scan + the 200/500 seam passes', function () {
    $app = bootstrapApp();
    $provider = new FireflyAutoConfigureServiceProvider($app);
    $app->instance('config', new Repository(['firefly' => []]));

    $passClasses = array_map(fn ($p) => $p::class, $provider->passes());

    expect($passClasses)->toContain(UserConfigurationsPass::class)
        ->and($passClasses)->toContain(ConditionPassOnePass::class)
        ->and($passClasses)->toContain(AutoConfigDiscoveryPass::class)
        ->and($passClasses)->toContain(AutoConfigurationsPass::class)
        ->and($passClasses)->toContain(ConditionPassTwoPass::class)
        ->and($passClasses)->toContain(FlushDefinitionsPass::class)
        ->and($provider->passes())->toHaveCount(11);
});
