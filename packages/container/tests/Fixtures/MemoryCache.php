<?php

declare(strict_types=1);

namespace Firefly\Container\Tests\Fixtures;

/**
 * Deliberately NOT annotated: produced exclusively by CacheConfig::memory(),
 * so a resolution that returns this instance proves the #[Bean] factory ran
 * rather than component auto-wiring.
 */
final class MemoryCache implements Cache
{
    public function label(): string
    {
        return 'memory';
    }
}
