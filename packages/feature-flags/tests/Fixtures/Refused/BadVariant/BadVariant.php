<?php

declare(strict_types=1);

namespace Firefly\FeatureFlags\Tests\Fixtures\Refused\BadVariant;

use Firefly\Container\Attributes\Service;
use Firefly\FeatureFlags\Gating\FeatureFlag;

#[Service]
class BadVariant
{
    #[FeatureFlag('x', variant: 'a,b')]
    public function run(): void {}
}
