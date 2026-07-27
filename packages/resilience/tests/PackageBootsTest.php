<?php

declare(strict_types=1);

use Firefly\Context\Boot\ApplicationContext;
use Firefly\Context\Boot\FireflyServiceProvider;
use Firefly\Resilience\ResilienceServiceProvider;

it('boots green when discovered alongside the bootstrap provider', function () {
    // needs: ['cache'] — a real app always has a cache store; the harness's isolated ArrayStore
    // fallback satisfies it so the T7 eager registry/store bean resolves here too.
    $app = fireflyApplication(
        ['firefly' => []],
        [ResilienceServiceProvider::class],
        needs: ['cache'],
    );

    expect($app->make(ApplicationContext::class))->toBeInstanceOf(ApplicationContext::class)
        ->and(new ResilienceServiceProvider($app))->toBeInstanceOf(FireflyServiceProvider::class);
});
