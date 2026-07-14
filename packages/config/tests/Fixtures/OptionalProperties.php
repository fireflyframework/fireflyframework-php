<?php

declare(strict_types=1);

namespace Firefly\Config\Tests\Fixtures;

final readonly class OptionalProperties
{
    public function __construct(
        public string $name,
        public ?string $nickname,
    ) {}
}
