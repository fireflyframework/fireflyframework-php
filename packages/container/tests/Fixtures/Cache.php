<?php

declare(strict_types=1);

namespace Firefly\Container\Tests\Fixtures;

/**
 * The classic "two beans, one type" contract: CacheConfig declares two #[Bean]
 * factory methods that BOTH return Cache. Neither MemoryCache nor RedisCache is
 * a #[Component] — they exist only as the products of those factories, so the
 * only way to reach either one is by BEAN NAME.
 */
interface Cache
{
    public function label(): string;
}
