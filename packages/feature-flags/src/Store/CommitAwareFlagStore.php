<?php

declare(strict_types=1);

namespace Firefly\FeatureFlags\Store;

use Closure;

/** A transactional store calls the callback after its own connection's root commit and drops it on rollback. */
interface CommitAwareFlagStore
{
    /** @param Closure(): void $callback */
    public function afterCommit(Closure $callback): void;
}
