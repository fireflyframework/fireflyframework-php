<?php

declare(strict_types=1);

namespace Firefly\Validation\Constraint;

use Attribute;
use Firefly\Validation\Rule\RoutingNumber as RoutingNumberRule;

#[Attribute(Attribute::TARGET_PARAMETER | Attribute::TARGET_PROPERTY)]
final class RoutingNumber implements Constraint, HasMessage
{
    use MessageElement;

    public function __construct(public readonly ?string $message = null) {}

    /** @return list<RoutingNumberRule> */
    public function toRules(): array
    {
        return [new RoutingNumberRule];
    }

    public function message(): ?string
    {
        return ConstraintMessage::resolve($this->message, 'must be a valid ABA routing number');
    }
}
