<?php

declare(strict_types=1);

use Firefly\Context\Boot\ApplicationContext;
use Firefly\Context\Boot\BootPass;
use Firefly\Context\Boot\FireflyServiceProvider;
use Firefly\Web\Dispatch\RouteWiringPass;
use Firefly\Web\Filter\FilterChainRegistrar;
use Firefly\Web\WebServiceProvider;

it('boots green when discovered alongside the bootstrap provider', function () {
    $app = fireflyApplication(['firefly' => []], [WebServiceProvider::class], needs: ['validation', 'http']);

    $passClasses = array_map(static fn (BootPass $pass): string => $pass::class, (new WebServiceProvider($app))->passes());

    expect($app->make(ApplicationContext::class))->toBeInstanceOf(ApplicationContext::class)
        ->and(new WebServiceProvider($app))->toBeInstanceOf(FireflyServiceProvider::class)
        ->and($passClasses)->toBe([RouteWiringPass::class, FilterChainRegistrar::class]);
});
