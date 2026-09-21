<?php

declare(strict_types=1);

namespace Firefly\Validation\Constraint;

use Attribute;

#[Attribute(Attribute::TARGET_PARAMETER | Attribute::TARGET_PROPERTY)]
final class Digits implements Constraint, HasMessage
{
    use MessageElement;

    public function __construct(
        public readonly int $integer,
        public readonly int $fraction = 0,
        public readonly ?string $message = null,
    ) {}

    public function toRules(): array
    {
        // Bounds BOTH the integer part (1..$integer digits) and the fraction part (1..$fraction digits),
        // D-anchored so a trailing newline cannot slip past $ (M5 /D discipline). String-only ⇒ the
        // manifest stores it as a plain string with no rehydration.
        $fraction = $this->fraction > 0
            ? '(\.\d{1,'.$this->fraction.'})?'
            : '';

        return ['numeric', 'regex:/^-?\d{1,'.$this->integer.'}'.$fraction.'$/D'];
    }

    public function message(): ?string
    {
        return ConstraintMessage::resolve(
            $this->message,
            "numeric value out of bounds (<{$this->integer} digits>.<{$this->fraction} digits> expected)",
            ['integer' => $this->integer, 'fraction' => $this->fraction],
        );
    }
}
