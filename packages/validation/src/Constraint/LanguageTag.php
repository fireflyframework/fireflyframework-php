<?php

declare(strict_types=1);

namespace Firefly\Validation\Constraint;

use Attribute;
use Firefly\Validation\Rule\LanguageTag as LanguageTagRule;

#[Attribute(Attribute::TARGET_PARAMETER | Attribute::TARGET_PROPERTY)]
final class LanguageTag implements Constraint, HasMessage
{
    use MessageElement;

    public function __construct(public readonly ?string $message = null) {}

    /** @return list<LanguageTagRule> */
    public function toRules(): array
    {
        return [new LanguageTagRule];
    }

    public function message(): ?string
    {
        return ConstraintMessage::resolve($this->message, 'must be a valid BCP 47 language tag');
    }
}
