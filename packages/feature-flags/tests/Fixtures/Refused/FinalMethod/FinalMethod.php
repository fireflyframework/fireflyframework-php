<?php

declare(strict_types=1);

namespace Firefly\FeatureFlags\Tests\Fixtures\Refused\FinalMethod;

use Firefly\Container\Attributes\Service;
use Firefly\FeatureFlags\Gating\FeatureFlag;

#[Service]
class FinalMethod
{
    #[FeatureFlag('x')]
    final public function run(): void {}
}
