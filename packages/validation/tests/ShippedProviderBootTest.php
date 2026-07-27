<?php

declare(strict_types=1);

use Firefly\Validation\IlluminateValidator;
use Firefly\Validation\ValidationServiceProvider;
use Firefly\Validation\Validator;
use Illuminate\Contracts\Validation\Factory;
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
    // ValidationAutoConfiguration's validator() bean needs Illuminate\Contracts\Validation\Factory — bound
    // explicitly here (NOT via needs: ['validation'], which pre-binds the Firefly Validator PORT itself and
    // would make #[ConditionalOnMissingBean(Validator)] back off, defeating this test's whole point).
    $context = bootFireflyApp(
        ['firefly' => []],
        [ValidationServiceProvider::class],
        bindings: [Factory::class => new IlluminateFactory(new Translator(new ArrayLoader, 'en'))],
    );

    expect($context->get(Validator::class))->toBeInstanceOf(IlluminateValidator::class);
});
