<?php

declare(strict_types=1);

namespace Firefly\OpenApi\Tests\ReturnFixture;

use Illuminate\Contracts\Support\Arrayable;

/**
 * Some state, in no particular shape.
 *
 * @implements Arrayable<array-key, mixed>
 */
final class Snapshot implements Arrayable
{
    public string $secret = 'never on the wire';

    public function toArray(): array
    {
        return ['anything' => true];
    }
}
