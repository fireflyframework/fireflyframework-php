<?php

declare(strict_types=1);

namespace Firefly\Tests\Support;

/**
 * One fenced code block, lifted out of a Markdown file with everything the three contracts need to judge it:
 * where it is (so a failure names a place a person can open), what language the fence claimed, the code
 * itself, and whichever marker comment stood immediately above it.
 *
 * The two markers are mutually exclusive by construction — the parser reads the first HTML comment above the
 * fence and stops — so a block carries at most one of them, and a block with neither is a block making a
 * claim nothing backs.
 */
final readonly class DocsCodeBlock
{
    public function __construct(
        public string $file,
        public int $line,
        public string $language,
        public string $code,
        public ?string $source,
        public ?string $illustrative,
    ) {}

    public function where(): string
    {
        return $this->file.':'.$this->line;
    }
}
