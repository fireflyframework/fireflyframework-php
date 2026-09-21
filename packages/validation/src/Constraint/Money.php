<?php

declare(strict_types=1);

namespace Firefly\Validation\Constraint;

use Attribute;
use Firefly\Validation\Rule\PositiveMoney;

#[Attribute(Attribute::TARGET_PARAMETER | Attribute::TARGET_PROPERTY)]
final class Money implements Constraint, HasMessage
{
    use MessageElement;

    public function __construct(public readonly ?string $message = null) {}

    /** @return list<PositiveMoney> */
    public function toRules(): array
    {
        return [new PositiveMoney];
    }

    public function message(): ?string
    {
        return ConstraintMessage::resolve($this->message, 'must be a positive monetary amount with at most two decimals');
    }
}
