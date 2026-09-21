<?php

declare(strict_types=1);

namespace Firefly\Validation\Constraint;

use Attribute;
use Firefly\Validation\Rule\DecimalScale as DecimalScaleRule;

#[Attribute(Attribute::TARGET_PARAMETER | Attribute::TARGET_PROPERTY)]
final class DecimalScale implements Constraint, HasMessage
{
    use MessageElement;

    public function __construct(
        public readonly int $scale,
        public readonly ?string $message = null,
    ) {}

    /** @return list<DecimalScaleRule> */
    public function toRules(): array
    {
        return [new DecimalScaleRule($this->scale)];
    }

    public function message(): ?string
    {
        return ConstraintMessage::resolve($this->message, "must have at most {$this->scale} fractional digits", ['scale' => $this->scale]);
    }
}
