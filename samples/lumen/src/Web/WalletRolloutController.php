<?php

declare(strict_types=1);

namespace Lumen\Web;

use Firefly\FeatureFlags\Gating\FeatureFlag;
use Firefly\Web\Attributes\GetMapping;
use Firefly\Web\Attributes\RestController;

#[RestController]
class WalletRolloutController
{
    /** @return array{label: string} */
    #[FeatureFlag('wallet-rollout-route')]
    #[GetMapping('/api/v1/wallet-rollout')]
    public function show(): array
    {
        return ['label' => 'Available balance'];
    }
}
