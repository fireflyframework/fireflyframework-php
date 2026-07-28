<?php

declare(strict_types=1);

namespace Lumen\Tests\Application;

use Firefly\Cqrs\Command\CommandBus;
use Lumen\Application\Command\Deposit;
use Lumen\Application\Command\OpenWallet;
use Lumen\Application\Command\Transfer;
use Lumen\Domain\Currency;
use Lumen\Domain\LedgerEntry;
use Lumen\Tests\LumenTestCase;

uses(LumenTestCase::class);

it('projects committed wallet events into ledger entries', function () {
    /** @var LumenTestCase $this */
    /** @var CommandBus $commands */
    $commands = $this->fireflyContext()->get(CommandBus::class);
    /** @var string $walletId */
    $walletId = $commands->send(new OpenWallet('owner-1', Currency::EUR));
    $commands->send(new Deposit($walletId, 5000));

    // The whole event chain must have fired end-to-end: the #[Transactional] Deposit committed -> the
    // DomainEventDispatcher drained FundsDeposited after commit -> the domain->integration bridge republished it to
    // the REAL InMemoryEventBus -> the SubscriberRegistry matched the #[EventListener] pattern against the eventType
    // (the short class name 'FundsDeposited', NOT the 'wallet.events' destination) -> the projector wrote a row. This
    // fails outright if the listener enumerated a 'wallet.*'-style destination glob (fnmatch would never match the
    // eventType), which is exactly what makes it a genuine guard on the projector's eventType-list #[EventListener].
    $entries = LedgerEntry::query()->where('wallet_id', $walletId)->get();
    expect($entries)->not->toBeEmpty();
    expect($entries->pluck('event_type')->all())->toContain('FundsDeposited');
})->group('lumen');

it('projects a completed transfer as a TransferCompleted ledger row keyed to the source wallet', function () {
    /** @var LumenTestCase $this */
    /** @var CommandBus $commands */
    $commands = $this->fireflyContext()->get(CommandBus::class);
    /** @var string $source */
    $source = $commands->send(new OpenWallet('owner-A', Currency::EUR));
    /** @var string $destination */
    $destination = $commands->send(new OpenWallet('owner-B', Currency::EUR));
    $commands->send(new Deposit($source, 10000));

    $commands->send(new Transfer($source, $destination, 4000));

    // A completed transfer raises TransferCompleted on the source AFTER both legs commit; the projector keys the row
    // to the source wallet (via sourceWalletId) and records the transferred amount (4000). Asserting the row exists
    // with that exact amount proves the event is actually raised + published + projected end-to-end — not merely
    // declared on the class and subscribed by the listener.
    expect(
        LedgerEntry::query()
            ->where('wallet_id', $source)
            ->where('event_type', 'TransferCompleted')
            ->where('amount_minor', 4000)
            ->exists()
    )->toBeTrue();

    // The low-level legs are still projected too: the source's FundsWithdrawn and the destination's FundsDeposited.
    expect(LedgerEntry::query()->where('wallet_id', $source)->pluck('event_type')->all())->toContain('FundsWithdrawn');
    expect(LedgerEntry::query()->where('wallet_id', $destination)->pluck('event_type')->all())->toContain('FundsDeposited');
})->group('lumen');
