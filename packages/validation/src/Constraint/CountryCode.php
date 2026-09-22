<?php

declare(strict_types=1);

namespace Firefly\Validation\Constraint;

use Attribute;
use Firefly\Validation\Rule\CountryCode as CountryCodeRule;

#[Attribute(Attribute::TARGET_PARAMETER | Attribute::TARGET_PROPERTY)]
final class CountryCode implements Constraint, HasMessage
{
    use MessageElement;

    public function __construct(public readonly ?string $message = null) {}

    /** @return list<CountryCodeRule> */
    public function toRules(): array
    {
        return [new CountryCodeRule];
    }

    public function message(): ?string
    {
        return ConstraintMessage::resolve($this->message, 'must be a valid ISO 3166-1 alpha-2 country code');
    }
}
