<?php

declare(strict_types=1);

namespace Firefly\OpenApi\Tests\ReturnFixture;

/**
 * A tag to apply.
 */
final readonly class TagRequest
{
    public function __construct(
        public int|string $ref,
        public ?string $note = null,
    ) {}
}
