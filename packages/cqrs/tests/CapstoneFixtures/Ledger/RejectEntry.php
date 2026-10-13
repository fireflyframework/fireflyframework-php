<?php

declare(strict_types=1);

namespace Firefly\Cqrs\Tests\CapstoneFixtures\Ledger;

use Firefly\Cqrs\Command\Command;

final readonly class RejectEntry implements Command
{
    public function __construct(public string $account, public int $amount) {}
}
