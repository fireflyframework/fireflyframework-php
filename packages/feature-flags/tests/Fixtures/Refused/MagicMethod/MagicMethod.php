<?php

declare(strict_types=1);

namespace Firefly\FeatureFlags\Tests\Fixtures\Refused\MagicMethod;

use Firefly\Container\Attributes\Service;
use Firefly\FeatureFlags\Gating\FeatureFlag;

#[Service]
class MagicMethod
{
    #[FeatureFlag('x')]
    public function __invoke(): void {}
}
