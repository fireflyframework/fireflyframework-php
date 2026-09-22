<?php

declare(strict_types=1);

use Firefly\Config\Config;
use Firefly\Container\Attributes\Configuration;
use Firefly\Container\Attributes\Order;
use Firefly\Context\Condition\Attributes\ConditionalOnMissingBean;
use Firefly\Validation\IlluminateValidator;
use Firefly\Validation\MessageStyle;
use Firefly\Validation\ValidationAutoConfiguration;
use Firefly\Validation\ValidationSettings;
use Firefly\Validation\Validator;
use Illuminate\Config\Repository;
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

it('its validator() bean builds the IlluminateValidator adapter over the settings bean', function () {
    $factory = new Factory(new Translator(new ArrayLoader, 'en'));
    $configuration = new ValidationAutoConfiguration;

    $settings = $configuration->validationSettings(new Config(new Repository(['firefly' => ['validation' => ['messages' => 'laravel']]])));
    $validator = $configuration->validator($factory, $settings);
    if (! $validator instanceof IlluminateValidator) {
        throw new RuntimeException('expected the IlluminateValidator adapter');
    }

    expect($settings)->toBeInstanceOf(ValidationSettings::class)
        ->and($settings->messages)->toBe(MessageStyle::Laravel)
        ->and($validator)->toBeInstanceOf(Validator::class)
        ->and($validator->settings())->toBe($settings);
});

it('gates the settings bean #[ConditionalOnMissingBean(ValidationSettings)] so an application may bind its own', function () {
    $method = (new ReflectionClass(ValidationAutoConfiguration::class))->getMethod('validationSettings');
    $condition = $method->getAttributes(ConditionalOnMissingBean::class)[0]->newInstance();

    expect($condition->type)->toBe(ValidationSettings::class)
        ->and((string) $method->getReturnType())->toBe(ValidationSettings::class);
});
