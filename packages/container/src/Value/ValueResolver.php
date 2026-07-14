<?php

declare(strict_types=1);

namespace Firefly\Container\Value;

interface ValueResolver
{
    public function resolve(string $expression): mixed;
}
