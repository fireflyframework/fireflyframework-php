<?php

declare(strict_types=1);

namespace Firefly\OpenApi\Tests\ReturnFixture;

use Illuminate\Contracts\Support\Arrayable;

/**
 * Open and closed crates, counted.
 *
 * @implements Arrayable<string, int>
 */
final class Tally implements Arrayable
{
    /** Public, and still not on the wire: JsonMessageConverter writes toArray(), not the properties. */
    public int $computedAt = 0;

    /** @return array{open: int, closed: int} */
    public function toArray(): array
    {
        return ['open' => 1, 'closed' => 2];
    }
}
