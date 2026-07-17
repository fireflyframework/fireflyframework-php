<?php

declare(strict_types=1);

namespace Firefly\Validation\Constraint;

use Attribute;
use Firefly\Validation\Rule\RoutingNumber as RoutingNumberRule;

#[Attribute(Attribute::TARGET_PARAMETER | Attribute::TARGET_PROPERTY)]
final class RoutingNumber implements Constraint
{
    /** @return list<RoutingNumberRule> */
    public function toRules(): array
    {
        return [new RoutingNumberRule];
    }
}
