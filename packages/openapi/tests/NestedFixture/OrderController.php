<?php

declare(strict_types=1);

namespace Firefly\OpenApi\Tests\NestedFixture;

use Firefly\Validation\Valid;
use Firefly\Web\Attributes\PostMapping;
use Firefly\Web\Attributes\RequestBody;
use Firefly\Web\Attributes\RequestMapping;
use Firefly\Web\Attributes\RestController;

/**
 * Orders.
 *
 * The controller exists so the nested fixtures are reached the way an application reaches them — through a
 * real #[RequestBody] binding compiled by the real RouteScanner, whose `dtos` table is the element-type
 * source the generator consumes.
 */
#[RestController]
#[RequestMapping('/api/orders')]
final class OrderController
{
    /**
     * Places an order.
     *
     * @return array<string, mixed>
     */
    #[PostMapping(status: 201, name: 'nested.orders.create')]
    public function create(#[Valid] #[RequestBody] CreateOrderRequest $body): array
    {
        return ['reference' => $body->reference];
    }
}
