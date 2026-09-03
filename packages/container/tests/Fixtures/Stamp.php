<?php

declare(strict_types=1);

namespace Firefly\Container\Tests\Fixtures;

final class Stamp
{
    public function __construct(public readonly string $ink) {}
}
