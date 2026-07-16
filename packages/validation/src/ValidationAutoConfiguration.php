<?php

declare(strict_types=1);

namespace Firefly\Validation;

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
 */
#[Configuration]
#[Order(1000)]
final class ValidationAutoConfiguration
{
    #[Bean]
    #[ConditionalOnMissingBean(Validator::class)]
    public function validator(Factory $factory): Validator
    {
        return new IlluminateValidator($factory);
    }
}
