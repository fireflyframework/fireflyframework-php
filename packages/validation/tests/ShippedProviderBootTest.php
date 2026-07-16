<?php

declare(strict_types=1);

use Firefly\AutoConfigure\FireflyAutoConfigureServiceProvider;
use Firefly\Context\Boot\ApplicationContext;
use Firefly\Validation\IlluminateValidator;
use Firefly\Validation\ValidationServiceProvider;
use Firefly\Validation\Validator;
use Illuminate\Config\Repository;
use Illuminate\Contracts\Validation\Factory;
use Illuminate\Foundation\Application;
use Illuminate\Translation\ArrayLoader;
use Illuminate\Translation\Translator;
use Illuminate\Validation\Factory as IlluminateFactory;

/**
 * REAL-PROVIDER end-to-end proof (stronger than the make-or-break Scenario 6, which drove a temp-manifest
 * double): the SHIPPED ValidationServiceProvider points at the COMMITTED cache manifests, and the REAL
 * bootstrap provider discovers/assembles them over the live boot pipeline. This is exactly what a real
 * `composer require firefly/validation` does — it must auto-wire the default Validator without crashing.
 */
it('auto-wires the default Validator by booting the shipped provider against its committed manifests', function () {
    $app = new Application;
    $app->instance('config', new Repository(['firefly' => []]));
    $app->instance(Factory::class, new IlluminateFactory(new Translator(new ArrayLoader, 'en')));

    $app->register(new ValidationServiceProvider($app));
    $app->register(new FireflyAutoConfigureServiceProvider($app));

    $app->boot();

    /** @var ApplicationContext $context */
    $context = $app->make(ApplicationContext::class);

    expect($context->get(Validator::class))->toBeInstanceOf(IlluminateValidator::class);
});
