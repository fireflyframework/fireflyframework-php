<?php

declare(strict_types=1);

namespace Firefly\Validation\Constraint;

use Attribute;

#[Attribute(Attribute::TARGET_PARAMETER | Attribute::TARGET_PROPERTY)]
final class Pattern implements Constraint, HasMessage
{
    use MessageElement;

    /** @param string $regex a full PCRE pattern WITH delimiters, e.g. '/^[A-Z]+$/D' */
    public function __construct(
        public readonly string $regex,
        public readonly ?string $message = null,
    ) {}

    public function toRules(): array
    {
        return ['regex:'.$this->regex];
    }

    public function message(): ?string
    {
        $bare = self::bare($this->regex);

        return ConstraintMessage::resolve($this->message, 'must match "'.$bare.'"', ['regexp' => $bare]);
    }

    /**
     * The expression without its PCRE delimiters and trailing modifiers: `/^[A-Z]+$/D` reads `^[A-Z]+$`,
     * which is how Spring's `must match "{regexp}"` shows a Java pattern and what a client can act on.
     * Bracket-style delimiters (`{…}`, `(…)`, `[…]`, `<…>`) close with their partner; anything that does
     * not parse as a delimited pattern is returned as written.
     */
    public static function bare(string $regex): string
    {
        if (strlen($regex) < 2) {
            return $regex;
        }

        $opening = $regex[0];
        if (ctype_alnum($opening) || ctype_space($opening) || $opening === '\\') {
            return $regex;
        }

        $closing = ['(' => ')', '{' => '}', '[' => ']', '<' => '>'][$opening] ?? $opening;
        $end = strrpos($regex, $closing);

        return $end === false || $end === 0 ? $regex : substr($regex, 1, $end - 1);
    }
}
