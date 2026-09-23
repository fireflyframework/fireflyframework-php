<?php

declare(strict_types=1);

namespace Firefly\OpenApi\Tests\ReturnFixture;

use Illuminate\Http\Resources\Attributes\Collects;
use Illuminate\Http\Resources\Json\ResourceCollection;

/**
 * A manifest: parcels, named by the #[Collects] attribute.
 */
#[Collects(ParcelResource::class)]
final class ManifestCollection extends ResourceCollection {}
