<?php

declare(strict_types=1);

namespace Firefly\OpenApi\Tests\ReturnFixture;

/**
 * A parcel as the API publishes it.
 */
final readonly class Parcel
{
    public function __construct(
        public string $barcode,
        public int $grams,
    ) {}
}
