<?php

declare(strict_types=1);

namespace Firefly\FeatureFlags\Store;

/** A source must defer reads, retaining its last good state and health, while this store has a transaction. */
interface TransactionAwareFlagStore
{
    public function transactionActive(): bool;
}
