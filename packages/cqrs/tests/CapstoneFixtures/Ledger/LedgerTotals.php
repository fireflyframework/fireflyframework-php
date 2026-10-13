<?php

declare(strict_types=1);

namespace Firefly\Cqrs\Tests\CapstoneFixtures\Ledger;

use Firefly\Cqrs\Attributes\CachedQuery;
use Firefly\Cqrs\Query\Query;

#[CachedQuery(group: 'ledger.totals')]
final readonly class LedgerTotals implements Query {}
