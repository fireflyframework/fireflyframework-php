<?php

declare(strict_types=1);

namespace Firefly\OpenApi\Generator;

/**
 * The tag one controller's operations are grouped under: the NAME every operation repeats, and the
 * DESCRIPTION that can only be stated once, in the document's root `tags` array.
 *
 * The split matters because OpenAPI puts the two in different places. An Operation Object's `tags` member is
 * a bare list of strings with nowhere to hang prose, so a tag description that is not lifted to the root is
 * a tag description that never reaches a reader.
 */
final readonly class TagDoc
{
    public function __construct(
        public string $name,
        public string $description,
    ) {}
}
