<?php

declare(strict_types=1);

namespace Firefly\Validation\Constraint;

use Attribute;
use Firefly\Validation\Rule\Percentage as PercentageRule;

#[Attribute(Attribute::TARGET_PARAMETER | Attribute::TARGET_PROPERTY)]
final class Percentage implements Constraint, HasMessage
{
    use MessageElement;

    public function __construct(public readonly ?string $message = null) {}

    /** @return list<PercentageRule> */
    public function toRules(): array
    {
        return [new PercentageRule];
    }

    public function message(): ?string
    {
        return ConstraintMessage::resolve($this->message, 'must be a percentage between 0 and 100');
    }
}
