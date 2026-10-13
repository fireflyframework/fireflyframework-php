<?php

declare(strict_types=1);

namespace Firefly\Cqrs\Tests\CapstoneFixtures\Ledger;

use Firefly\Container\Attributes\Repository;
use Firefly\Data\Repository\EloquentRepository;

/**
 * @extends EloquentRepository<Entry>
 */
#[Repository]
class EntryRepository extends EloquentRepository
{
    protected string $model = Entry::class;
}
