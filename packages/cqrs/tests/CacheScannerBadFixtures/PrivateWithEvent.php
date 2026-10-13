<?php

declare(strict_types=1);

namespace Firefly\Cqrs\Tests\CacheScannerBadFixtures;

use Firefly\Cqrs\Attributes\InvalidatesQuery;

#[InvalidatesQuery(PrivateLedger::class, with: ['account' => 'account'])]
final class PrivateWithEvent
{
    public function __construct(public string $account = 'a') {}
}
