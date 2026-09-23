<?php

declare(strict_types=1);

namespace Firefly\OpenApi\Tests\ReturnFixture;

/**
 * A printed label.
 */
final readonly class Label
{
    public function __construct(
        public string $text,
    ) {}
}
