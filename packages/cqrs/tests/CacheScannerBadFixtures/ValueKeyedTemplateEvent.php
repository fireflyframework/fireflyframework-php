<?php

declare(strict_types=1);

namespace Firefly\Cqrs\Tests\CacheScannerBadFixtures;

use Firefly\Cqrs\Attributes\InvalidatesQuery;
use Firefly\Cqrs\Tests\CacheScannerFixtures\LedgerBalance;

#[InvalidatesQuery(LedgerBalance::class, key: 'balance:{account}')]
final class ValueKeyedTemplateEvent
{
    public function __construct(public string $account = 'a') {}
}
