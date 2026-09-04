<?php

declare(strict_types=1);

namespace Firefly\OpenApi\Tests\ResponseFixture;

use Firefly\OpenApi\Attributes\ApiResponse;
use Firefly\Web\Attributes\DeleteMapping;
use Firefly\Web\Attributes\GetMapping;
use Firefly\Web\Attributes\PathVariable;
use Firefly\Web\Attributes\PostMapping;
use Firefly\Web\Attributes\RequestMapping;
use Firefly\Web\Attributes\RestController;

/**
 * The fixture for RESPONSE shapes: one action per way a LaraFly action states what it returns.
 *
 * Every one of these used to document itself as `{"type": "object"}` — a body with no members — because the
 * generator read the declared PHP return type and stopped. `array` is the declared type of five of the six
 * actions below, and `array` says nothing at all.
 */
#[RestController]
#[RequestMapping('/api/consignments')]
final class ConsignmentController
{
    /**
     * A page of consignments.
     *
     * @return array{page: positive-int, size: positive-int, total: int, items: list<Consignment>}
     */
    #[GetMapping]
    public function index(): array
    {
        return ['page' => 1, 'size' => 20, 'total' => 0, 'items' => []];
    }

    /**
     * One consignment.
     *
     * @param  non-empty-string  $reference
     */
    #[GetMapping('/{reference}')]
    public function show(#[PathVariable] string $reference): Consignment
    {
        return new Consignment($reference, new Money(0, Currency::Eur), []);
    }

    /**
     * The shipments on a consignment.
     *
     * @return list<Shipment> newest first
     */
    #[GetMapping('/{reference}/shipments')]
    public function shipments(#[PathVariable] string $reference): array
    {
        return [];
    }

    /**
     * Total declared value per currency.
     *
     * @return array<string, Money>
     */
    #[GetMapping('/totals')]
    public function totals(): array
    {
        return [];
    }

    /**
     * Books a consignment.
     *
     * @return array<string, mixed>
     */
    #[PostMapping(status: 201)]
    #[ApiResponse(status: 409, description: 'A consignment with that reference already exists.', type: Consignment::class)]
    #[ApiResponse(status: 202, description: 'Accepted for later booking.', type: 'list<Shipment>')]
    public function book(): array
    {
        return [];
    }

    #[DeleteMapping('/{reference}', status: 204)]
    public function cancel(#[PathVariable] string $reference): void {}
}
