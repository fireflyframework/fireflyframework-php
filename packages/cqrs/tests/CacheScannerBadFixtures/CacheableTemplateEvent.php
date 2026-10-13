<?php

declare(strict_types=1);

namespace Firefly\Cqrs\Tests\CacheScannerBadFixtures;

use Firefly\Cqrs\Attributes\InvalidatesQuery;

#[InvalidatesQuery(KeyedLedger::class, key: 'ledger:{account}')]
final class CacheableTemplateEvent
{
    public function __construct(public string $account = 'a') {}
}
