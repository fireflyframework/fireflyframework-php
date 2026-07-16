<?php

declare(strict_types=1);

use Firefly\AutoConfigure\AutoConfiguration;
use Firefly\AutoConfigure\AutoConfigurationCollector;
use Firefly\Validation\ValidationServiceProvider;
use Illuminate\Foundation\Application;

it('is a discovered AutoConfiguration that records validation candidacy at register() time', function () {
    $app = new Application;
    $app->register(new ValidationServiceProvider($app));

    expect(new ValidationServiceProvider($app))->toBeInstanceOf(AutoConfiguration::class);

    $collector = $app->make(AutoConfigurationCollector::class);
    expect($collector->all())->toHaveCount(1)
        ->and($collector->all()[0]->provider)->toBe(ValidationServiceProvider::class)
        ->and($collector->all()[0]->componentManifestPath)->toEndWith('firefly-validation-components.php')
        ->and($collector->all()[0]->contextManifestPath)->toEndWith('firefly-validation-context.php');
});
