<?php

declare(strict_types=1);

use Firefly\AutoConfigure\AutoConfiguration;
use Firefly\AutoConfigure\AutoConfigurationCollector;
use Firefly\Validation\ValidationServiceProvider;
use Illuminate\Contracts\Validation\Factory;
use Illuminate\Translation\ArrayLoader;
use Illuminate\Translation\Translator;
use Illuminate\Validation\Factory as IlluminateFactory;

it('is a discovered AutoConfiguration that records validation candidacy at register() time', function () {
    // #[ConditionalOnMissingBean(Validator)] checks the Firefly BeanDefinitionRegistry, not the Illuminate
    // container — needs: ['validation'] (which instance-binds the Firefly Validator PORT directly) would
    // NOT satisfy it, so ValidationAutoConfiguration's validator() bean still runs during boot and needs a
    // real Illuminate\Contracts\Validation\Factory — bound explicitly here, same as ShippedProviderBootTest.
    $app = fireflyApplication(
        providers: [ValidationServiceProvider::class],
        bindings: [Factory::class => new IlluminateFactory(new Translator(new ArrayLoader, 'en'))],
    );

    expect(new ValidationServiceProvider($app))->toBeInstanceOf(AutoConfiguration::class);

    $collector = $app->make(AutoConfigurationCollector::class);
    expect($collector->all())->toHaveCount(1)
        ->and($collector->all()[0]->provider)->toBe(ValidationServiceProvider::class)
        ->and($collector->all()[0]->componentManifestPath)->toEndWith('firefly-validation-components.php')
        ->and($collector->all()[0]->contextManifestPath)->toEndWith('firefly-validation-context.php');
});
