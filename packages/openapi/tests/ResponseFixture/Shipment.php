<?php

declare(strict_types=1);

namespace Firefly\OpenApi\Tests\ResponseFixture;

use DateTimeImmutable;

/** Where a consignment is, and when it is expected. */
final readonly class Shipment
{
    /**
     * @param  list<string>  $checkpoints  where it has been scanned, oldest first
     */
    public function __construct(
        public string $carrier,
        public ?string $tracking,
        public array $checkpoints,
        public ?DateTimeImmutable $expectedAt,
    ) {}
}
