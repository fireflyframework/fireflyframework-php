<?php

declare(strict_types=1);

use Firefly\Context\Boot\ApplicationContext;
use Firefly\Orchestration\OrchestrationServiceProvider;
use Firefly\Orchestration\OrchestrationWiringProvider;

/**
 * The two discovered providers boot over the real pipeline next to the bootstrap provider — exactly what
 * `composer require firefly/orchestration` does. Task 17 extends this file once the package has beans.
 */
it('boots green when discovered alongside the bootstrap provider', function (): void {
    $app = fireflyApplication(
        ['firefly' => []],
        [OrchestrationServiceProvider::class, OrchestrationWiringProvider::class],
        needs: ['cache'],
    );

    expect($app->make(ApplicationContext::class))->toBeInstanceOf(ApplicationContext::class);
});
