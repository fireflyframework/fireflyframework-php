<?php

declare(strict_types=1);

namespace Firefly\Cqrs\Tests\CacheScannerBadFixtures;

use Firefly\Cqrs\Attributes\CachedQuery;
use Firefly\Cqrs\Query\Query;

#[CachedQuery]
final readonly class PrivateLedger implements Query
{
    public function __construct(private string $account = 'a') {}

    public function account(): string
    {
        return $this->account;
    }
}
