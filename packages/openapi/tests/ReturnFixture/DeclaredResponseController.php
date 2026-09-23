<?php

declare(strict_types=1);

namespace Firefly\OpenApi\Tests\ReturnFixture;

use Firefly\OpenApi\Attributes\ApiResponse;
use Firefly\Web\Attributes\GetMapping;
use Firefly\Web\Attributes\PathVariable;
use Firefly\Web\Attributes\RequestMapping;
use Firefly\Web\Attributes\RestController;

/**
 * #[ApiResponse] without a `type`, on each kind of status it can name.
 */
#[RestController]
#[RequestMapping('/declared')]
final class DeclaredResponseController
{
    /** Read one parcel. */
    #[GetMapping('/{barcode}')]
    #[ApiResponse(status: 200, description: 'The parcel as last scanned.')]
    #[ApiResponse(status: 404, description: 'No parcel carries that barcode.')]
    #[ApiResponse(status: 'default', description: 'Anything else the depot refuses.')]
    #[ApiResponse(status: '5XX', description: 'The depot is unreachable.')]
    #[ApiResponse(status: 202, description: 'Queued for a rescan; ask again later.')]
    public function show(#[PathVariable] string $barcode): Parcel
    {
        return new Parcel($barcode, 0);
    }
}
