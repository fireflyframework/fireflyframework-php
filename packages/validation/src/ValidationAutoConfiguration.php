<?php

declare(strict_types=1);

namespace Firefly\Validation;

use Firefly\Config\Config;
use Firefly\Container\Attributes\Bean;
use Firefly\Container\Attributes\Configuration;
use Firefly\Container\Attributes\Order;
use Firefly\Context\Condition\Attributes\ConditionalOnMissingBean;
use Illuminate\Contracts\Validation\Factory;

/**
 * The first real consumer of the M5 machinery. A pure #[Configuration] (scanned into firefly/validation's
 * compiled manifests, NOT a ServiceProvider). #[Order(1000)] places it after user definitions in
 * ConditionPassTwoPass's (order, FQCN) sort; the validator() bean is gated #[ConditionalOnMissingBean(Validator)]
 * so, once the app binds its own Validator, this default backs off — the canonical Spring Boot starter shape.
 *
 * validationSettings() is the same shape one level down: the `firefly.validation.*` keys read once through the
 * Config port, gated so an application (or a test) may bind a ValidationSettings of its own and have the
 * default validator built over it.
 */
#[Configuration]
#[Order(1000)]
final class ValidationAutoConfiguration
{
    #[Bean]
    #[ConditionalOnMissingBean(ValidationSettings::class)]
    public function validationSettings(Config $config): ValidationSettings
    {
        return ValidationSettings::fromConfig($config);
    }

    #[Bean]
    #[ConditionalOnMissingBean(Validator::class)]
    public function validator(Factory $factory, ValidationSettings $settings): Validator
    {
        return new IlluminateValidator($factory, $settings);
    }
}
