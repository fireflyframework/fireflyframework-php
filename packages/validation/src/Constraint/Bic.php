<?php

declare(strict_types=1);

namespace Firefly\Validation\Constraint;

use Attribute;
use Firefly\Validation\Rule\Bic as BicRule;

#[Attribute(Attribute::TARGET_PARAMETER | Attribute::TARGET_PROPERTY)]
final class Bic implements Constraint, HasMessage
{
    use MessageElement;

    public function __construct(public readonly ?string $message = null) {}

    /** @return list<BicRule> */
    public function toRules(): array
    {
        return [new BicRule];
    }

    public function message(): ?string
    {
        return ConstraintMessage::resolve($this->message, 'must be a valid BIC');
    }
}
