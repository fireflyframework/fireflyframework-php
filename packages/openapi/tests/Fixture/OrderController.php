<?php

declare(strict_types=1);

namespace Firefly\OpenApi\Tests\Fixture;

use Firefly\Validation\Valid;
use Firefly\Web\Attributes\DeleteMapping;
use Firefly\Web\Attributes\GetMapping;
use Firefly\Web\Attributes\PathVariable;
use Firefly\Web\Attributes\PostMapping;
use Firefly\Web\Attributes\QueryParam;
use Firefly\Web\Attributes\RequestBody;
use Firefly\Web\Attributes\RequestHeader;
use Firefly\Web\Attributes\RequestMapping;
use Firefly\Web\Attributes\RestController;

/**
 * The fixture the generator tests run against — a real #[RestController] scanned by the real RouteScanner,
 * not a hand-built RouteDescriptor. That is the point: the tests assert the pipeline the framework actually
 * uses end to end, so a change to RouteScanner's binding plan or to a #[Constraint]'s toRules() surfaces here
 * rather than in an app's generated document.
 */
#[RestController]
#[RequestMapping('/api/orders')]
final class OrderController
{
    /** @return array<string, mixed> */
    #[GetMapping('/{id}')]
    public function show(
        #[PathVariable] string $id,
        #[QueryParam(name: 'expand', default: false)] bool $expand = false,
        #[RequestHeader(name: 'X-Tenant')] ?string $tenant = null,
    ): array {
        return ['id' => $id, 'expand' => $expand, 'tenant' => $tenant];
    }

    /** @return array<string, mixed> */
    #[PostMapping(status: 201, name: 'orders.create')]
    public function create(#[Valid] #[RequestBody] CreateOrderRequest $body): array
    {
        return ['reference' => $body->reference];
    }

    #[DeleteMapping('/{id}', status: 204)]
    public function cancel(#[PathVariable] string $id): void {}
}
