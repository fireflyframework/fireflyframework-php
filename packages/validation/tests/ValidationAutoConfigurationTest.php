<?php

declare(strict_types=1);

use Firefly\Container\Attributes\Configuration;
use Firefly\Container\Attributes\Order;
use Firefly\Context\Condition\Attributes\ConditionalOnMissingBean;
use Firefly\Validation\IlluminateValidator;
use Firefly\Validation\ValidationAutoConfiguration;
use Firefly\Validation\Validator;
use Illuminate\Translation\ArrayLoader;
use Illuminate\Translation\Translator;
use Illuminate\Validation\Factory;

it('is a #[Configuration] ordered 1000 whose validator() bean is gated #[ConditionalOnMissingBean(Validator)]', function () {
    $class = new ReflectionClass(ValidationAutoConfiguration::class);

    expect($class->getAttributes(Configuration::class))->not->toBe([])
        ->and($class->getAttributes(Order::class)[0]->newInstance()->order)->toBe(1000);

    $method = $class->getMethod('validator');
    $condition = $method->getAttributes(ConditionalOnMissingBean::class)[0]->newInstance();

    expect($condition->type)->toBe(Validator::class)
        ->and((string) $method->getReturnType())->toBe(Validator::class);
});

it('its validator() bean builds the IlluminateValidator adapter', function () {
    $factory = new Factory(new Translator(new ArrayLoader, 'en'));
    $validator = (new ValidationAutoConfiguration)->validator($factory);

    expect($validator)->toBeInstanceOf(IlluminateValidator::class)
        ->and($validator)->toBeInstanceOf(Validator::class);
});
