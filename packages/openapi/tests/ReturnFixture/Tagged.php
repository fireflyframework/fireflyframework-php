<?php

declare(strict_types=1);

namespace Firefly\OpenApi\Tests\ReturnFixture;

/**
 * Something carrying a tag whose members are declared with PHP union types.
 */
final readonly class Tagged
{
    public function __construct(
        public int|string $ref,
        public Parcel|Label|null $subject,
        public ?Parcel $parcel,
    ) {}
}
