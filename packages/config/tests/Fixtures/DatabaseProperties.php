<?php

declare(strict_types=1);

namespace Firefly\Config\Tests\Fixtures;

use Firefly\Config\Attributes\ConfigProperties;

final readonly class Pool
{
    public function __construct(
        public int $min = 1,
        public int $max = 10,
    ) {}
}

#[ConfigProperties('database')]
final readonly class DatabaseProperties
{
    /**
     * @param  list<string>  $replicas
     */
    public function __construct(
        public string $driver,
        public Pool $pool,
        public array $replicas = [],
    ) {}
}
