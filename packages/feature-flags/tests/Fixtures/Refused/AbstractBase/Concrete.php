<?php

declare(strict_types=1);

namespace Firefly\FeatureFlags\Tests\Fixtures\Refused\AbstractBase;

use Firefly\Container\Attributes\Service;
use Firefly\FeatureFlags\Gating\FeatureFlag;

#[FeatureFlag('x')]
abstract class Base
{
    public function run(): void {}
}

#[Service]
class Concrete extends Base {}
