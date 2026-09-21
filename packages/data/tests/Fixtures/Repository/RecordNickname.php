<?php

declare(strict_types=1);

namespace Firefly\Data\Tests\Fixtures\Repository;

/** A projection that asks for a column `records` does not have — the "missing column at first use" case. */
final readonly class RecordNickname
{
    public function __construct(
        public int $id,
        public string $nickname,
    ) {}
}
