<?php

declare(strict_types=1);

namespace Lumen\Tests\Application;

use Firefly\Cqrs\Command\CommandBus;
use Lumen\Application\Command\Deposit;
use Lumen\Application\Command\OpenWallet;
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
