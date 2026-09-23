<?php

declare(strict_types=1);

namespace Firefly\Observability\Tests\Fixtures\Method;

use Firefly\Container\Attributes\Service;
use Firefly\Observability\Method\Counted;

#[Service]
class CountedService
{
    #[Counted]
    public function refund(): void {}
}
