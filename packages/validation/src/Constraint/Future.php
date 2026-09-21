<?php

declare(strict_types=1);

namespace Firefly\Validation\Constraint;

use Attribute;

#[Attribute(Attribute::TARGET_PARAMETER | Attribute::TARGET_PROPERTY)]
final class Future implements Constraint, HasMessage
{
    use MessageElement;

    public function __construct(public readonly ?string $message = null) {}

    public function toRules(): array
    {
        return ['date', 'after:now'];
    }

    public function message(): ?string
    {
        return ConstraintMessage::resolve($this->message, 'must be a future date');
    }
}
