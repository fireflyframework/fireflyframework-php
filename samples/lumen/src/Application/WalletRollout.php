<?php

declare(strict_types=1);

namespace Lumen\Application;

use Firefly\FeatureFlags\FeatureFlags;

final class WalletRollout
{
    public function __construct(private readonly FeatureFlags $flags) {}

    public function balanceLabel(): string
    {
        return $this->flags->isEnabled('wallet-balance-v2') ? 'Available balance' : 'Balance';
    }

    public function checkoutView(): string
    {
        return match ($this->flags->getString('wallet-checkout-view', 'legacy')) {
            'v2' => 'wallet.v2',
            default => 'wallet.legacy',
        };
    }
}
