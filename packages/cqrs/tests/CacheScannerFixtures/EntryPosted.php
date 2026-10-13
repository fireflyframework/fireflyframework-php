<?php

declare(strict_types=1);

namespace Firefly\Cqrs\Tests\CacheScannerFixtures;

use Firefly\Cqrs\Attributes\InvalidatesQuery;
use Firefly\Domain\DomainEvent;

#[InvalidatesQuery(LedgerBalance::class, with: ['account' => 'account'])]
#[InvalidatesQuery(LedgerTotals::class, eventType: 'ledger.entry-posted')]
final readonly class EntryPosted extends DomainEvent
{
    public function __construct(public string $account, public int $amount)
    {
        parent::__construct();
    }
}
