<?php

declare(strict_types=1);

namespace Firefly\Validation\Constraint;

use Attribute;
use Firefly\Validation\Rule\Luhn as LuhnRule;

#[Attribute(Attribute::TARGET_PARAMETER | Attribute::TARGET_PROPERTY)]
final class Luhn implements Constraint, HasMessage
{
    use MessageElement;

    public function __construct(public readonly ?string $message = null) {}

    /** @return list<LuhnRule> */
    public function toRules(): array
    {
        return [new LuhnRule];
    }

    public function message(): ?string
    {
        return ConstraintMessage::resolve($this->message, 'must pass the Luhn checksum');
    }
}
