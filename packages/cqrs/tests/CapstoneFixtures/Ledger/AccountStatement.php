<?php

declare(strict_types=1);

namespace Firefly\Cqrs\Tests\CapstoneFixtures\Ledger;

use Firefly\Cqrs\Attributes\CachedQuery;
use Firefly\Cqrs\Cache\Cacheable;
use Firefly\Cqrs\Query\Query;

/** A query with its OWN key: #[InvalidatesQuery(key: 'statement:{account}')] evicts exactly this entry. */
#[CachedQuery(group: 'ledger.statements')]
final readonly class AccountStatement implements Cacheable, Query
{
    public function __construct(public string $account) {}

    public function cacheKey(): string
    {
        return 'statement:'.$this->account;
    }
}
