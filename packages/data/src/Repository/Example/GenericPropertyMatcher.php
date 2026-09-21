<?php

declare(strict_types=1);

namespace Firefly\Data\Repository\Example;

/**
 * A per-path override for ExampleMatcher::withMatcher(): its own string matcher, and optionally its own case
 * setting (null inherits the example's). Spring's GenericPropertyMatcher, minus regex/transformers.
 */
final readonly class GenericPropertyMatcher
{
    private function __construct(
        public StringMatcher $stringMatcher,
        public ?bool $ignoreCase,
    ) {}

    public static function of(StringMatcher $matcher): self
    {
        return new self($matcher, null);
    }

    public static function exact(): self
    {
        return new self(StringMatcher::EXACT, null);
    }

    public static function contains(): self
    {
        return new self(StringMatcher::CONTAINING, null);
    }

    public static function startsWith(): self
    {
        return new self(StringMatcher::STARTING, null);
    }

    public static function endsWith(): self
    {
        return new self(StringMatcher::ENDING, null);
    }

    public function ignoreCase(): self
    {
        return new self($this->stringMatcher, true);
    }

    public function caseSensitive(): self
    {
        return new self($this->stringMatcher, false);
    }
}
