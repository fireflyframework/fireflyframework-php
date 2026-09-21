<?php

declare(strict_types=1);

namespace Firefly\Validation\Constraint;

use Attribute;

#[Attribute(Attribute::TARGET_PARAMETER | Attribute::TARGET_PROPERTY)]
final class Max implements Constraint, HasMessage
{
    use MessageElement;

    public function __construct(
        public readonly int|float $value,
        public readonly ?string $message = null,
    ) {}

    public function toRules(): array
    {
        return ['numeric', 'lte:'.$this->value];
    }

    public function message(): ?string
    {
        return ConstraintMessage::resolve($this->message, "must be less than or equal to {$this->value}", ['value' => $this->value]);
    }
}
