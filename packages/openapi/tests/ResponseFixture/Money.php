<?php

declare(strict_types=1);

namespace Firefly\OpenApi\Tests\ResponseFixture;

/** An amount in minor units, with the currency it is denominated in. */
final readonly class Money
{
    public function __construct(
        public int $minor,
        public Currency $currency,
    ) {}
}
