<?php

declare(strict_types=1);

namespace Firefly\OpenApi\Tests\ReturnFixture;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A parcel as the API resource presents it.
 */
final class ParcelResource extends JsonResource
{
    /** @return array{barcode: string, grams: int} */
    public function toArray(Request $request): array
    {
        return ['barcode' => 'P-1', 'grams' => 120];
    }
}
