<?php

declare(strict_types=1);

namespace Firefly\Cqrs\Tests\CacheScannerBadFixtures;

use Firefly\Cqrs\Attributes\CachedQuery;
use Firefly\Cqrs\Query\Query;

#[CachedQuery]
final readonly class PagedLedger implements Query
{
    public function __construct(public string $account, public int $page = 1) {}
}
