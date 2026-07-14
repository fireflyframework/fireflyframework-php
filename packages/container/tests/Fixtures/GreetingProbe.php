<?php

declare(strict_types=1);

namespace Firefly\Container\Tests\Fixtures;

use Firefly\Container\Attributes\Value;

final class GreetingProbe
{
    public function __construct(
        #[Value('${FIREFLY_GREETING:Hi}')] public readonly string $greeting,
    ) {}
}
