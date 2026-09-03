<?php

declare(strict_types=1);

namespace Firefly\OpenApi\Tests\AttributeFixture;

use Firefly\OpenApi\Attributes\ApiIgnore;
use Firefly\OpenApi\Attributes\ApiOperation;
use Firefly\OpenApi\Attributes\ApiParameter;
use Firefly\OpenApi\Attributes\ApiResponse;
use Firefly\OpenApi\Attributes\ApiTag;
use Firefly\Validation\Valid;
use Firefly\Web\Attributes\GetMapping;
use Firefly\Web\Attributes\PathVariable;
use Firefly\Web\Attributes\PostMapping;
use Firefly\Web\Attributes\QueryParam;
use Firefly\Web\Attributes\RequestBody;
use Firefly\Web\Attributes\RequestMapping;
use Firefly\Web\Attributes\RestController;

/**
 * A class docblock that must NOT reach the document, because #[ApiTag] states both the name and the
 * description and an attribute beats a docblock.
 *
 * It is here precisely so the test can prove the docblock LOSES: a fixture whose two sources agree would pass
 * whichever one the generator actually read.
 */
#[RestController]
#[RequestMapping('/inventory')]
#[ApiTag(name: 'Warehouse', description: 'Stock levels, movements and manual adjustments.')]
final class InventoryController
{
    /**
     * A summary the attribute overrides. This second paragraph must not appear as a description either.
     *
     * @return array<string, mixed>
     */
    #[GetMapping('/{sku}')]
    #[ApiOperation(
        summary: 'Read one stock level',
        description: 'Live and uncached: the number returned is the number the warehouse would pick against right now.',
        operationId: 'stockLevel',
        tags: ['Warehouse', 'Reporting'],
    )]
    #[ApiResponse(status: 404, description: 'No such stock-keeping unit.')]
    #[ApiResponse(status: 200, description: 'The current stock level.', type: StockLevel::class)]
    // `required: false` is stated here and must be DROPPED: a path parameter is required by the 3.1
    // meta-schema itself, so honouring the override would emit a document a strict validator rejects.
    #[ApiParameter(name: 'sku', description: 'The stock-keeping unit to read.', example: 'ACME-001', required: false)]
    #[ApiParameter(name: 'at', description: 'Read the level as it stood at this instant.', example: '2026-01-01T00:00:00Z', required: true)]
    // Nothing binds `tenant`: it is not a #[PathVariable], a #[QueryParam] or a #[RequestHeader] on this
    // method, so the dispatcher will never read it and it must not appear in the document either.
    #[ApiParameter(name: 'tenant', description: 'A parameter this endpoint does not actually take.')]
    public function level(
        #[PathVariable] string $sku,
        #[QueryParam(name: 'at')] ?string $at = null,
    ): array {
        return ['sku' => $sku, 'at' => $at];
    }

    /**
     * Read the stock level as it stood at the close of a named accounting period.
     *
     * Declared SECOND on purpose, claiming an operationId the method above already claimed: an attribute
     * chooses the id, it does not get to hand two operations the same one.
     *
     * @return array<string, mixed>
     */
    #[GetMapping('/{sku}/closing/{period}')]
    #[ApiOperation(operationId: 'stockLevel')]
    public function closingLevel(
        #[PathVariable] string $sku,
        #[PathVariable] string $period,
    ): array {
        return ['sku' => $sku, 'period' => $period];
    }

    /**
     * Apply a manual stock correction.
     *
     * The summary and description here survive: #[ApiOperation] states only `deprecated`, and an omitted
     * member falls through to the docblock rather than blanking it.
     *
     * @return array<string, mixed>
     */
    #[PostMapping('/adjustments', status: 201)]
    #[ApiOperation(deprecated: true)]
    public function adjust(#[Valid] #[RequestBody] AdjustmentRequest $body): array
    {
        return ['sku' => $body->sku];
    }

    /**
     * Dump the warehouse's internal reconciliation state.
     *
     * A real route, deliberately undocumented: it exists for one deploy while a client migrates, and putting
     * it in the published contract would invite somebody to build on it.
     *
     * @return array<string, mixed>
     */
    #[GetMapping('/debug/reconciliation')]
    #[ApiIgnore]
    public function reconciliation(): array
    {
        return [];
    }
}
