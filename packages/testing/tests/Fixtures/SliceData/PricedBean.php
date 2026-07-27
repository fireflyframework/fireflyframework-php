<?php

declare(strict_types=1);

namespace Firefly\Testing\Tests\Fixtures\SliceData;

use Firefly\Container\Attributes\Service;

#[Service]
final class PricedBean
{
    public function __construct(private readonly PricingPort $pricing) {}

    public function price(): int
    {
        return $this->pricing->price();
    }
}
