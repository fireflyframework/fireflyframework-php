<?php

declare(strict_types=1);

namespace Lumen\Application;

use Firefly\Container\Attributes\Service;
use Firefly\FeatureFlags\Gating\FeatureFlag;

#[Service]
class WalletRolloutGate
{
    #[FeatureFlag('wallet-premium-label', fallback: 'legacyLabel')]
    public function label(string $name): string
    {
        return "Welcome back, {$name}";
    }

    public function legacyLabel(string $name): string
    {
        return "Hello {$name}";
    }
}
