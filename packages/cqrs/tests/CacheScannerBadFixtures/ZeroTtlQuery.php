<?php

declare(strict_types=1);

namespace Firefly\Cqrs\Tests\CacheScannerBadFixtures;

use Firefly\Cqrs\Attributes\CachedQuery;

#[CachedQuery(ttl: 0)]
final class ZeroTtlQuery {}
