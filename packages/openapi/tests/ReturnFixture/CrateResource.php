<?php

declare(strict_types=1);

namespace Firefly\OpenApi\Tests\ReturnFixture;

use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A crate, exactly as its model serialises — JsonResource's own toArray() hands back the model's.
 *
 * @mixin CrateEntity
 */
final class CrateResource extends JsonResource {}
