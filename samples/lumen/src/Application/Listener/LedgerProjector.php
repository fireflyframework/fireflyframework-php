<?php

declare(strict_types=1);

namespace Lumen\Application\Listener;

use Firefly\Container\Attributes\Component;
use Firefly\Eda\Attributes\EventListener;
use Firefly\Eda\EventEnvelope;
use Lumen\Domain\LedgerEntry;

/**
 * The read-model projector: turns committed wallet integration events into `ledger_entries` rows (an append-only
 * audit ledger the GetLedger query reads back). It closes the full event chain the sample exercises — a
 * #[Transactional] command commits, the DomainEventDispatcher drains the aggregate's domain events after commit, the
 * firefly/cqrs domain->integration bridge republishes each one to the eda EventPublisher (the memory InMemoryEventBus
 * on the default provider), and the SubscriberRegistry delivers the matching envelope here.
 *
 * The #[EventListener] MUST enumerate the event TYPE names, not the #[PublishDomainEvent('wallet.events')] DESTINATION:
 * SubscriberRegistry::deliver() calls fnmatch($pattern, $envelope->eventType), matching the pattern against the
 * eventType (the short class name, e.g. 'FundsDeposited') and NEVER against the destination. A 'wallet.*'-style pattern
 * would therefore never match any wallet event and this projector would silently never fire.
 */
#[Component]
final class LedgerProjector
{
    #[EventListener(['WalletOpened', 'FundsDeposited', 'FundsWithdrawn', 'TransferCompleted'])]
    public function onWalletEvent(EventEnvelope $envelope): void
    {
        // The envelope payload is array<string, mixed> (get_object_vars of the domain event, seen through the broker
        // boundary), so each field is narrowed to its projected type — a WalletOpened carries no amount/balance, so
        // those default to 0, and TransferCompleted carries no walletId, so it defaults to ''.
        $walletId = $envelope->payload['walletId'] ?? '';
        $amountMinor = $envelope->payload['amountMinor'] ?? 0;
        $balanceMinor = $envelope->payload['balanceMinor'] ?? 0;

        LedgerEntry::query()->create([
            'wallet_id' => is_string($walletId) ? $walletId : '',
            'event_type' => $envelope->eventType,
            'amount_minor' => is_int($amountMinor) ? $amountMinor : 0,
            'balance_minor' => is_int($balanceMinor) ? $balanceMinor : 0,
            'occurred_at' => now(),
        ]);
    }
}
