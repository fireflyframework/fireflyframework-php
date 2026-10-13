<?php

declare(strict_types=1);

namespace Firefly\Cqrs\Tests\CapstoneFixtures\Ledger;

use Firefly\Cqrs\Attributes\CachedQuery;
use Firefly\Cqrs\Query\Query;

/** A value-keyed query with an enum property, so its QueryCacheKey digest writes the tier by case. */
#[CachedQuery(ttl: 60)]
final readonly class TierBalance implements Query
{
    public function __construct(public string $account, public LedgerTier $tier) {}
}
