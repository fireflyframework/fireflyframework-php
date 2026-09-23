<?php

declare(strict_types=1);

namespace Firefly\OpenApi\Tests\ReturnFixture;

use Illuminate\Contracts\Support\Arrayable;
use JsonSerializable;

/**
 * Two shapes, of which the converter writes one.
 *
 * @implements Arrayable<string, string>
 */
final class Dual implements Arrayable, JsonSerializable
{
    /** @return array{source: string} */
    public function toArray(): array
    {
        return ['source' => 'arrayable'];
    }

    /** @return array{json: string} */
    public function jsonSerialize(): array
    {
        return ['json' => 'serializable'];
    }
}
