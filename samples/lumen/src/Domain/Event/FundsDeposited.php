<?php

declare(strict_types=1);

namespace Lumen\Domain\Event;

use Firefly\Cqrs\Attributes\InvalidatesQuery;
use Firefly\Cqrs\Attributes\PublishDomainEvent;
use Firefly\Domain\DomainEvent;
use Lumen\Application\Query\GetBalance;

/**
 * Raised by Wallet::deposit(). It also says what it makes stale: the cached GetBalance of the same wallet, matched
 * property for property (`walletId` of the query = `walletId` of the event), evicted once the deposit commits.
 */
#[PublishDomainEvent('wallet.events')]
#[InvalidatesQuery(GetBalance::class, with: ['walletId' => 'walletId'])]
final readonly class FundsDeposited extends DomainEvent
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
