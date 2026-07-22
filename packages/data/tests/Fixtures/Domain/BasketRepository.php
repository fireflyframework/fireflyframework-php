<?php

declare(strict_types=1);

namespace Firefly\Data\Tests\Fixtures\Domain;

use Firefly\Container\Attributes\Repository;
use Firefly\Data\Repository\EloquentRepository;
use Firefly\Data\Tests\Fixtures\Repository\Record;

/**
 * Exercises the base save() tracking path: save(aggregate) registers the aggregate for after-commit dispatch. A
 * pure Basket is not an Eloquent model, so base save() persists nothing for it — it only tracks. `$model` is
 * required by the base contract (used by CRUD/derived queries) but is irrelevant to aggregate tracking here.
 *
 * @extends EloquentRepository<Record>
 */
#[Repository]
class BasketRepository extends EloquentRepository
{
    protected string $model = Record::class;
}
