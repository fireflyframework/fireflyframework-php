<?php

declare(strict_types=1);

namespace Firefly\Cqrs\Tests\CapstoneFixtures\Banking;

use Firefly\Cqrs\Attributes\QueryHandler;

#[QueryHandler]
final class FindAccountHandler
{
    public function handle(FindAccount $query): int
    {
        return Account::query()->where('owner', $query->owner)->count();
    }
}
