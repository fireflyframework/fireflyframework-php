<?php

declare(strict_types=1);

use Firefly\Context\Boot\ApplicationContext;
use Firefly\Observability\ObservabilityServiceProvider;
use Firefly\Observability\ObservabilityWiringProvider;

it('boots a bare skeleton with the observability providers registered', function () {
    $app = fireflyApplication(
        config: ['firefly' => ['observability' => ['metrics' => ['enabled' => true]]]],
        providers: [ObservabilityServiceProvider::class, ObservabilityWiringProvider::class],
    );

    expect($app->make(ApplicationContext::class))->toBeInstanceOf(ApplicationContext::class);
});
