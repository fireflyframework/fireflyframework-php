<?php

declare(strict_types=1);

namespace Lumen\Application\Query;

use Firefly\Cqrs\Attributes\CachedQuery;

/**
 * Query: read a wallet's current balance in minor units. GetBalanceHandler returns null for an unknown wallet.
 *
 * Cached for 60 seconds once firefly.cqrs.query.cache.provider=cache, keyed by its one public property: two
 * GetBalance('w-1') share an entry, GetBalance('w-2') has its own. A null answer is never cached. FundsDeposited and
 * FundsWithdrawn evict the entry of the wallet they changed, so the TTL only bounds staleness from writes that bypass
 * the bus.
 */
#[CachedQuery(ttl: 60)]
final readonly class GetBalance
{
    public function __construct(public string $walletId) {}
}
