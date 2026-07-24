<?php

declare(strict_types=1);

namespace Firefly\Cqrs\Tests\CapstoneFixtures\Banking;

use Firefly\Cqrs\Cache\Cacheable;
use Firefly\Cqrs\Query\Query;

final readonly class FindAccount implements Cacheable, Query
{
    public function __construct(public string $owner) {}

    public function cacheKey(): string
    {
        return 'accounts:'.$this->owner; // exercises the inert QueryCache seam (NoOp -> always miss)
    }
}
