<?php

declare(strict_types=1);

namespace Firefly\Cqrs\Tests\Support;

/** The same application with firefly.cqrs.query.cache.provider=none — what every app had before this wave. */
abstract class CqrsNoCacheCapstoneTestCase extends CqrsCacheCapstoneTestCase
{
    protected function cacheProvider(): string
    {
        return 'none';
    }
}
