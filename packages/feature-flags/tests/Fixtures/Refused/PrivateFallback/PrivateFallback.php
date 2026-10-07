<?php

declare(strict_types=1);

namespace Firefly\FeatureFlags\Tests\Fixtures\Refused\PrivateFallback;

use Firefly\Container\Attributes\Service;
use Firefly\FeatureFlags\Gating\FeatureFlag;

#[Service]
class PrivateFallback
{
    #[FeatureFlag('x', fallback: 'recover')]
    public function run(): void
    {
        $this->recover();
    }

    private function recover(): void {}
}
