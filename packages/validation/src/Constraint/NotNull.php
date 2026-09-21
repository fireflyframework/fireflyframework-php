<?php

declare(strict_types=1);

namespace Firefly\Validation\Constraint;

use Attribute;
use Firefly\Validation\Rule\NotNull as NotNullRule;

#[Attribute(Attribute::TARGET_PARAMETER | Attribute::TARGET_PROPERTY)]
final class NotNull implements Constraint, HasMessage
{
    use MessageElement;

    public function __construct(public readonly ?string $message = null) {}

    public function toRules(): array
    {
        return ['present', new NotNullRule];
    }

    public function message(): ?string
    {
        return ConstraintMessage::resolve($this->message, 'must not be null');
    }
}
