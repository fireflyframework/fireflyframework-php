<?php

declare(strict_types=1);

namespace Firefly\Cqrs\Tests\CapstoneFixtures\Banking;

use Firefly\Cqrs\Attributes\PublishDomainEvent;
use Firefly\Domain\DomainEvent;

#[PublishDomainEvent('accounts.events')]
final readonly class AccountOpened extends DomainEvent
{
    public function __construct(public string $owner, public int $balance)
    {
        parent::__construct();
    }
}
