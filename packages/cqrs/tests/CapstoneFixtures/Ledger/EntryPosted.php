<?php

declare(strict_types=1);

namespace Firefly\Cqrs\Tests\CapstoneFixtures\Ledger;

use Firefly\Cqrs\Attributes\InvalidatesQuery;
use Firefly\Cqrs\Attributes\PublishDomainEvent;
use Firefly\Domain\DomainEvent;

#[PublishDomainEvent('ledger.events')]
#[InvalidatesQuery(LedgerBalance::class, with: ['account' => 'account'])]
#[InvalidatesQuery(LedgerTotals::class)]
#[InvalidatesQuery(AccountStatement::class, key: 'statement:{account}')]
final readonly class EntryPosted extends DomainEvent
{
    public function __construct(public string $account, public int $amount)
    {
        parent::__construct();
    }
}
