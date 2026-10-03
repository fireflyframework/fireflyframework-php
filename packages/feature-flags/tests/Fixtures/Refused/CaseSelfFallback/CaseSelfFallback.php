<?php

declare(strict_types=1);

namespace Firefly\FeatureFlags\Tests\Fixtures\Refused\CaseSelfFallback;

use Firefly\Container\Attributes\Service;
use Firefly\FeatureFlags\Gating\FeatureFlag;

#[Service]
class CaseSelfFallback
{
    #[FeatureFlag('off', fallback: 'RUN')]
    public function run(): string
    {
        return 'GATED BODY RAN';
    }
}
