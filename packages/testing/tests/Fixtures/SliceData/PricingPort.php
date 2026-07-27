<?php

declare(strict_types=1);

namespace Firefly\Testing\Tests\Fixtures\SliceData;

interface PricingPort
{
    public function price(): int;
}
