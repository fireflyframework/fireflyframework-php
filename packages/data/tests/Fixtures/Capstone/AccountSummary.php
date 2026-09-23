<?php

declare(strict_types=1);

namespace Firefly\Data\Tests\Fixtures\Capstone;

/** The DTO the capstone's paged #[Projection] hydrates — two of the `accounts` table's two columns. */
final readonly class AccountSummary
{
    public function __construct(
        public int $id,
        public string $name,
    ) {}
}
