<?php

declare(strict_types=1);

namespace Firefly\Data\Tests\Fixtures\Transactional;

use Firefly\Data\Repository\Attributes\Query;
use Firefly\Data\Transaction\Attributes\Transactional;
use Firefly\Data\Transaction\Propagation;

/**
 * Class-level #[Transactional] (read-only default) + a method-level override + a #[Query] method — the shape the
 * scanner must resolve. NOT `final`: an inheritance proxy must be able to extend it.
 */
#[Transactional(readOnly: true)]
class TransferService
{
    #[Transactional(propagation: Propagation::REQUIRES_NEW)]
    public function transfer(int $amount): int
    {
        return $amount;
    }

    public function balance(): int
    {
        return 0;
    }

    /** @return list<array<string, mixed>> */
    #[Query('select * from accounts where name = :name')]
    public function findByName(string $name): array
    {
        return [];
    }
}
