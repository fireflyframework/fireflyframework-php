<?php

declare(strict_types=1);

namespace Firefly\Cqrs\Tests\CacheScannerBadFixtures;

use Firefly\Cqrs\Attributes\InvalidatesQuery;

#[InvalidatesQuery(KeyedLedger::class, with: ['account' => 'account'])]
final class CacheableWithEvent
{
    public function __construct(public string $account = 'a') {}
}
