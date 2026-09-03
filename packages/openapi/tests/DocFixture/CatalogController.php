<?php

declare(strict_types=1);

namespace Firefly\OpenApi\Tests\DocFixture;

use Firefly\Validation\Valid;
use Firefly\Web\Attributes\GetMapping;
use Firefly\Web\Attributes\PathVariable;
use Firefly\Web\Attributes\PostMapping;
use Firefly\Web\Attributes\QueryParam;
use Firefly\Web\Attributes\RequestBody;
use Firefly\Web\Attributes\RequestMapping;
use Firefly\Web\Attributes\RestController;

/**
 * The public product catalogue.
 *
 * Everything here is readable without authentication; stock reservation is the one action that changes state,
 * and it changes it only for as long as the reservation lives.
 *
 * This fixture deliberately carries NOT ONE attribute from Firefly\OpenApi\Attributes. It is the "documented
 * only by PHPDoc" half of the parity tests, so every word that reaches the generated document from here got
 * there because the generator read prose a developer had already written for a human reader — which is the
 * entire claim being tested.
 */
#[RestController]
#[RequestMapping('/catalog')]
final class CatalogController
{
    /**
     * List the products in one category. Withdrawn lines are never included, even when their category still
     * exists.
     *
     * The `page` cursor is opaque: echo back exactly what the previous response returned. Cursors built by
     * hand are not supported and may stop resolving at any time.
     *
     * @return array<string, mixed>
     */
    #[GetMapping('/{category}')]
    public function list(
        #[PathVariable] string $category,
        #[QueryParam(name: 'page')] ?string $page = null,
    ): array {
        return ['category' => $category, 'page' => $page];
    }

    /**
     * Hold stock for a shopper who has not paid yet.
     *
     * @return array<string, mixed>
     */
    #[PostMapping('/reservations', status: 202)]
    public function reserve(#[Valid] #[RequestBody] ReservationRequest $body): array
    {
        return ['basket' => $body->basket];
    }

    /**
     * Look one product up by the barcode printed on it.
     *
     * @deprecated Superseded by the catalogue search endpoint, which accepts a barcode among other terms.
     *
     * @return array<string, mixed>
     */
    #[GetMapping('/barcode/{code}')]
    public function byBarcode(#[PathVariable] string $code): array
    {
        return ['code' => $code];
    }

    /** @return array<string, mixed> */
    #[GetMapping('/health')]
    public function undocumented(): array
    {
        return [];
    }
}
