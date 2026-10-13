<?php

declare(strict_types=1);

namespace Lumen\Tests\Application;

use Lumen\Tests\LumenTestCase;

/**
 * The lumen boot harness with the CQRS query cache switched on: CacheQueryCache on the array store, so a
 * #[CachedQuery] query (GetBalance) is answered from the cache and an #[InvalidatesQuery] event (FundsDeposited,
 * FundsWithdrawn) evicts it after its transaction commits. LumenTestCase itself keeps the default `none`, so the rest
 * of the suite still proves the uncached behaviour.
 */
abstract class LumenQueryCacheTestCase extends LumenTestCase
{
    /** @return array<string, mixed> */
    protected function configOverrides(): array
    {
        return [
            ...parent::configOverrides(),
            'cache.default' => 'array',
            'firefly.cqrs.query.cache.provider' => 'cache',
        ];
    }
}
