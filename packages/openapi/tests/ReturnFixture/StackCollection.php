<?php

declare(strict_types=1);

namespace Firefly\OpenApi\Tests\ReturnFixture;

use Illuminate\Http\Resources\Json\ResourceCollection;

/**
 * Crates stacked together, named by the `$collects` property.
 */
final class StackCollection extends ResourceCollection
{
    /** @var class-string */
    public $collects = CrateResource::class;
}
