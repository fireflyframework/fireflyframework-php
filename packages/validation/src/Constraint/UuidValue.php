<?php

declare(strict_types=1);

namespace Firefly\Validation\Constraint;

use Attribute;
use Firefly\Validation\Rule\Uuid;

#[Attribute(Attribute::TARGET_PARAMETER | Attribute::TARGET_PROPERTY)]
final class UuidValue implements Constraint, HasMessage
{
    use MessageElement;

    public function __construct(public readonly ?string $message = null) {}

    /** @return list<Uuid> */
    public function toRules(): array
    {
        return [new Uuid];
    }

    public function message(): ?string
    {
        return ConstraintMessage::resolve($this->message, 'must be a valid UUID');
    }
}
