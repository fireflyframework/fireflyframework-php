<?php

declare(strict_types=1);

namespace Firefly\Security\Tests\Fixtures\Flows;

use Firefly\Web\Attributes\GetMapping;
use Firefly\Web\Attributes\RestController;

/** A machine surface under api/* — the json-paths default — that must never redirect to a login page. */
#[RestController]
final class OrdersApiController
{
    /** @return array{orders: list<string>} */
    #[GetMapping('/api/orders')]
    public function index(): array
    {
        return ['orders' => ['order-1']];
    }
}
