<?php

declare(strict_types=1);

namespace Firefly\Container\Tests\Fixtures;

final class ApiToken
{
    public function __construct(public readonly string $value) {}
}
