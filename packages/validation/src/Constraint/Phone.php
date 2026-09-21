<?php

declare(strict_types=1);

namespace Firefly\Validation\Constraint;

use Attribute;
use Firefly\Validation\Rule\E164;

#[Attribute(Attribute::TARGET_PARAMETER | Attribute::TARGET_PROPERTY)]
final class Phone implements Constraint, HasMessage
{
    use MessageElement;

    public function __construct(public readonly ?string $message = null) {}

    /** @return list<E164> */
    public function toRules(): array
    {
        return [new E164];
    }

    public function message(): ?string
    {
        return ConstraintMessage::resolve($this->message, 'must be a valid E.164 phone number');
    }
}
