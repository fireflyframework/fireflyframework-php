<?php

declare(strict_types=1);

namespace Firefly\Cqrs\Tests\CapstoneFixtures\Banking;

use Firefly\Container\Attributes\Repository;
use Firefly\Data\Repository\EloquentRepository;

/**
 * @extends EloquentRepository<Account>
 */
#[Repository]
class AccountRepository extends EloquentRepository
{
    protected string $model = Account::class;
}
