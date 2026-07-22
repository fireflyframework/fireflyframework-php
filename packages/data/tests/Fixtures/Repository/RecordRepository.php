<?php

declare(strict_types=1);

namespace Firefly\Data\Tests\Fixtures\Repository;

use Firefly\Container\Attributes\Repository;
use Firefly\Data\Repository\EloquentRepository;

/**
 * A concrete #[Repository] over Record — the canonical "user repository" shape from design §3.1: set the model
 * class-string and inherit the whole contract. Task 13 adds derived-query @method hints + a #[Query] method.
 *
 * @extends EloquentRepository<Record>
 */
#[Repository]
class RecordRepository extends EloquentRepository
{
    protected string $model = Record::class;
}
