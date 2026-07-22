<?php

declare(strict_types=1);

namespace Firefly\Data\Tests\Fixtures\Ordering;

use Firefly\Container\Attributes\Repository;
use Firefly\Data\Repository\EloquentRepository;

/**
 * The user repository: a derived query (findByStatusOrderByCreatedAtDesc, resolved by __call, typed via @method)
 * over the Order model. NO save() override — the base EloquentRepository::save() persists the Model AND tracks it
 * (Order implements RecordsDomainEvents) for after-commit dispatch, one object doing both. The @method hint gives
 * PHPStan the derived-query signature.
 *
 * @extends EloquentRepository<Order>
 *
 * @method list<Order> findByStatusOrderByCreatedAtDesc(string $status)
 */
#[Repository]
class OrderRepository extends EloquentRepository
{
    protected string $model = Order::class;
}
