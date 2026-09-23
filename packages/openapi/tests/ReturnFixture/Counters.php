<?php

declare(strict_types=1);

namespace Firefly\OpenApi\Tests\ReturnFixture;

use Illuminate\Contracts\Support\Arrayable;

/**
 * A count per bucket.
 *
 * @implements Arrayable<string, int>
 */
final class Counters implements Arrayable
{
    public function toArray(): array
    {
        return ['a' => 1];
    }
}
