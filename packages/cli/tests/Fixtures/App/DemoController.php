<?php

declare(strict_types=1);

namespace Firefly\Cli\Tests\Fixtures\App;

use Firefly\Web\Attributes\GetMapping;
use Firefly\Web\Attributes\RestController;

#[RestController]
final class DemoController
{
    /** @return array<string,bool> */
    #[GetMapping('/demo')]
    public function index(): array
    {
        return ['ok' => true];
    }
}
