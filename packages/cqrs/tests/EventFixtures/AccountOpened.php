<?php

declare(strict_types=1);

namespace Firefly\Cqrs\Tests\EventFixtures;

use Firefly\Domain\DomainEvent;

final readonly class AccountOpened extends DomainEvent
{
    public function __construct(public string $accountId, public int $balance)
    {
        parent::__construct();
    }
}
