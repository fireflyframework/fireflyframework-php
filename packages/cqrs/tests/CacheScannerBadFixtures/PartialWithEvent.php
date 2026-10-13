<?php

declare(strict_types=1);

namespace Firefly\Cqrs\Tests\CacheScannerBadFixtures;

use Firefly\Cqrs\Attributes\InvalidatesQuery;

#[InvalidatesQuery(PagedLedger::class, with: ['account' => 'account'])]
final class PartialWithEvent
{
    public function __construct(public string $account = 'a') {}
}
