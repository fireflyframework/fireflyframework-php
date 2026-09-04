<?php

declare(strict_types=1);

namespace App\Orders;

use Firefly\Container\Attributes\Repository;
use Firefly\Data\Repository\EloquentRepository;

/**
 * The order-line store.
 *
 * A second repository for a second entity, which is the ordinary shape once a model has more than one table
 * — and, incidentally, what gives the admin dashboard two browsable resources to walk between rather than
 * one to look at.
 *
 * `findByOrderId()` is a derived query: the parser reads the method NAME, splits it into a property and a
 * comparison, and builds the query. There is no body worth the name and no SQL anywhere.
 *
 * @extends EloquentRepository<OrderLineEntity>
 */
#[Repository]
class OrderLineRepository extends EloquentRepository
{
    /** @var class-string<OrderLineEntity> */
    protected string $model = OrderLineEntity::class;

    /**
     * Every line of one order, in insertion order.
     *
     * @return list<OrderLineEntity>
     */
    public function findByOrderIdOrderByIdAsc(int $orderId): array
    {
        $rows = $this->dispatchQuery(__FUNCTION__, func_get_args());
        assert(is_array($rows));

        /** @var list<OrderLineEntity> $rows */
        return $rows;
    }
}
