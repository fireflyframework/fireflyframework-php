<?php

declare(strict_types=1);

namespace Firefly\Container\Tests\Fixtures;

use Firefly\Container\Attributes\Qualifier;
use Firefly\Container\Attributes\Service;

/**
 * A constructor parameter that asks for the NON-primary Cache bean by name.
 *
 * Cache::class alone resolves to the #[Primary] bean (MemoryCache), so this
 * component only ever receives a RedisCache if #[Qualifier] is actually read
 * during constructor injection. Before the fix the attribute was inert: the
 * parameter silently got whatever the type resolved to, with no error.
 */
#[Service]
final class CacheConsumer
{
    public function __construct(
        #[Qualifier('redisCache')]
        public readonly Cache $cache,
    ) {}
}
