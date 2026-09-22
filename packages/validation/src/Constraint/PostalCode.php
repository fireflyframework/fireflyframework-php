<?php

declare(strict_types=1);

namespace Firefly\Validation\Constraint;

use Attribute;
use Firefly\Validation\Rule\PostalCode as PostalCodeRule;

#[Attribute(Attribute::TARGET_PARAMETER | Attribute::TARGET_PROPERTY)]
final class PostalCode implements Constraint, HasMessage
{
    use MessageElement;

    public function __construct(public readonly ?string $message = null) {}

    /** @return list<PostalCodeRule> */
    public function toRules(): array
    {
        return [new PostalCodeRule];
    }

    public function message(): ?string
    {
        return ConstraintMessage::resolve($this->message, 'must be a valid postal code');
    }
}
