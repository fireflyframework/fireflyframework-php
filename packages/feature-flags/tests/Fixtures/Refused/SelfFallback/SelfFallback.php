<?php

declare(strict_types=1);

namespace Firefly\FeatureFlags\Tests\Fixtures\Refused\SelfFallback;

use Firefly\Container\Attributes\Service;
use Firefly\FeatureFlags\Gating\FeatureFlag;

#[Service]
class SelfFallback
{
    #[FeatureFlag('x', fallback: 'run')]
    public function run(): void {}
}
