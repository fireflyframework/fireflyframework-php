<?php

declare(strict_types=1);

namespace Firefly\Testing\Tests\Fixtures\Slice;

use Firefly\Web\Attributes\GetMapping;
use Firefly\Web\Attributes\RestController;

#[RestController]
final class SliceController
{
    /** @return array<string,bool> */
    #[GetMapping('/slice/ping')]
    public function ping(): array
    {
        return ['pong' => true];
    }
}
