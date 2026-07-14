<?php

declare(strict_types=1);

namespace Firefly\Config\Tests\Fixtures;

use Firefly\Config\Attributes\ConfigProperties;

#[ConfigProperties('mail')]
final readonly class MailProperties
{
    public function __construct(
        public string $host,
        public int $port = 25,
        public bool $tls = false,
    ) {}
}
