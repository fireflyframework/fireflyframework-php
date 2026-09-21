<?php

declare(strict_types=1);

namespace Firefly\Validation\Constraint;

use Attribute;

#[Attribute(Attribute::TARGET_PARAMETER | Attribute::TARGET_PROPERTY)]
final class NegativeOrZero implements Constraint, HasMessage
{
    use MessageElement;

    public function __construct(public readonly ?string $message = null) {}

    public function toRules(): array
    {
        return ['numeric', 'lte:0'];
    }

    public function message(): ?string
    {
        return ConstraintMessage::resolve($this->message, 'must be less than or equal to 0');
    }
}
