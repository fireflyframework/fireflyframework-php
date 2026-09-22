<?php

declare(strict_types=1);

namespace Firefly\Data\Tests\Fixtures\Repository;

/** A class-based projection over three `records` columns; `email` is nullable in the table and here. */
final readonly class RecordSummary
{
    public function __construct(
        public int $id,
        public ?string $email,
        public int $amount,
    ) {}
}
