<?php

declare(strict_types=1);

namespace Firefly\Container\Tests\Fixtures;

final class Clock
{
    public function __construct(public readonly string $zone) {}
}
