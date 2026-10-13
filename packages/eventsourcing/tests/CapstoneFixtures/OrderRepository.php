<?php

declare(strict_types=1);

namespace Firefly\EventSourcing\Tests\CapstoneFixtures;

use Firefly\Container\Attributes\Component;
use Firefly\EventSourcing\Aggregate\EventSourcedRepository;
use Firefly\EventSourcing\Aggregate\EventSourcingSupport;
use Firefly\EventSourcing\Tests\Fixtures\Order;

/** @extends EventSourcedRepository<Order> */
#[Component]
final class OrderRepository extends EventSourcedRepository
{
    public function __construct(EventSourcingSupport $support)
    {
        parent::__construct($support, Order::class);
    }
}
