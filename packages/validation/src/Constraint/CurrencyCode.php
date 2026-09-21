<?php

declare(strict_types=1);

namespace Firefly\Validation\Constraint;

use Attribute;
use Firefly\Validation\Rule\Currency;

#[Attribute(Attribute::TARGET_PARAMETER | Attribute::TARGET_PROPERTY)]
final class CurrencyCode implements Constraint, HasMessage
{
    use MessageElement;

    public function __construct(public readonly ?string $message = null) {}

    /** @return list<Currency> */
    public function toRules(): array
    {
        return [new Currency];
    }

    public function message(): ?string
    {
        return ConstraintMessage::resolve($this->message, 'must be a valid ISO 4217 currency code');
    }
}
