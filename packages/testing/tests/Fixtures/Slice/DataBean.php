<?php

declare(strict_types=1);

namespace Firefly\Testing\Tests\Fixtures\Slice;

use Firefly\Container\Attributes\Service;

#[Service]
final class DataBean
{
    public function label(): string
    {
        return 'data-bean';
    }
}
