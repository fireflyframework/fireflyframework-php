<?php

declare(strict_types=1);

namespace Firefly\Cli\Tests\Fixtures\App;

use Firefly\Container\Attributes\Service;

#[Service]
final class DemoService
{
    public function greet(): string
    {
        return 'hello';
    }
}
