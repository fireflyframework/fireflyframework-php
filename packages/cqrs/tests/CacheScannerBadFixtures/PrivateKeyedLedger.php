<?php

declare(strict_types=1);

namespace Firefly\Cqrs\Tests\CacheScannerBadFixtures;

use Firefly\Cqrs\Attributes\CachedQuery;
use Firefly\Cqrs\Cache\Cacheable;
use Firefly\Cqrs\Query\Query;

#[CachedQuery]
final readonly class PrivateKeyedLedger implements Cacheable, Query
{
    public function __construct(private string $account = 'a') {}

    public function cacheKey(): string
    {
        return 'ledger:'.$this->account;
    }
}
