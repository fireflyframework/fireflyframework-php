<?php

declare(strict_types=1);

namespace Lumen\Domain\Event;

use Firefly\Cqrs\Attributes\InvalidatesQuery;
use Firefly\Cqrs\Attributes\PublishDomainEvent;
use Firefly\Domain\DomainEvent;
use Lumen\Application\Query\GetBalance;

/**
 * Raised by Wallet::withdraw(). Evicts the cached GetBalance of the same wallet once the withdrawal commits.
 */
#[PublishDomainEvent('wallet.events')]
#[InvalidatesQuery(GetBalance::class, with: ['walletId' => 'walletId'])]
final readonly class FundsWithdrawn extends DomainEvent
{
    public function __construct(
        public string $walletId,
        public int $amountMinor,
        public string $currency,
        public int $balanceMinor,
    ) {
        parent::__construct();
    }
}
