<?php

declare(strict_types=1);

namespace Firefly\Actuator\Tests\Support;

/** A second cache store that cannot be written (a file store under /dev/null): the component must go DOWN. */
abstract class BrokenCacheHealthCapstoneTestCase extends InfrastructureHealthCapstoneTestCase
{
    /** @return array<string, mixed> */
    protected function configOverrides(): array
    {
        return [
            ...parent::configOverrides(),
            'cache.stores.broken' => ['driver' => 'file', 'path' => '/dev/null/firefly-health'],
            'firefly.management.endpoint.health.cache.stores' => ['array', 'broken'],
        ];
    }
}
