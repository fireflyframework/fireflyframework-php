<?php

declare(strict_types=1);

namespace Firefly\Cqrs\Tests\CapstoneFixtures\Ledger;

/** A backed enum a query is keyed by: JSON carries it as 'gold', never as the case, across a broker. */
enum LedgerTier: string
{
    case Gold = 'gold';
    case Silver = 'silver';
}
