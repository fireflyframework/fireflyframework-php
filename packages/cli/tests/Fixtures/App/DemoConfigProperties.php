<?php

declare(strict_types=1);

namespace Firefly\Cli\Tests\Fixtures\App;

use Firefly\Config\Attributes\ConfigProperties;

#[ConfigProperties('demo')]
final readonly class DemoConfigProperties
{
    public function __construct(public string $greeting = 'hello') {}
}
