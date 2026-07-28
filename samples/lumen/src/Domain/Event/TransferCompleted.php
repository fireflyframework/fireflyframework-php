<?php

declare(strict_types=1);

namespace Lumen\Domain\Event;

use Firefly\Cqrs\Attributes\PublishDomainEvent;
use Firefly\Domain\DomainEvent;

#[PublishDomainEvent('wallet.events')]
final readonly class TransferCompleted extends DomainEvent
{
    public function __construct(
        public string $sourceWalletId,
        public string $destinationWalletId,
        public int $amountMinor,
        public string $currency,
    ) {
        parent::__construct();
    }
}
