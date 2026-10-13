<?php

declare(strict_types=1);

namespace Firefly\Cqrs\Tests\CacheScannerFixtures;

use Firefly\Cqrs\Attributes\CachedQuery;
use Firefly\Cqrs\Query\Query;

#[CachedQuery(ttl: 60)]
final readonly class LedgerBalance implements Query
{
    public function __construct(public string $account) {}
}
