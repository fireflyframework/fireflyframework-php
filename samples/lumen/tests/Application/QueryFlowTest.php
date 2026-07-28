<?php

declare(strict_types=1);

namespace Lumen\Tests\Application;

use Firefly\Cqrs\Command\CommandBus;
use Firefly\Cqrs\Query\QueryBus;
use Lumen\Application\Command\OpenWallet;
use Lumen\Application\Query\GetBalance;
use Lumen\Application\Query\GetLedger;
use Lumen\Domain\Currency;
use Lumen\Domain\LedgerEntry;
use Lumen\Tests\LumenTestCase;

uses(LumenTestCase::class);

it('reads a zero balance and the projected WalletOpened entry for a freshly opened wallet', function () {
    /** @var LumenTestCase $this */
    /** @var CommandBus $commands */
    $commands = $this->fireflyContext()->get(CommandBus::class);
    /** @var QueryBus $queries */
    $queries = $this->fireflyContext()->get(QueryBus::class);

    /** @var string $walletId */
    $walletId = $commands->send(new OpenWallet('owner-q', Currency::USD));

    // A freshly opened wallet holds nothing; but S5's LedgerProjector is now live, so the WalletOpened event that the
    // committed OpenWallet emitted has already been projected into a single ledger row (event_type='WalletOpened') —
    // proving the same event chain the projector test exercises fires here too, through the ordinary query surface.
    expect($queries->ask(new GetBalance($walletId)))->toBe(0);
    expect($queries->ask(new GetLedger($walletId)))->toHaveCount(1);
    expect(LedgerEntry::query()->where('wallet_id', $walletId)->pluck('event_type')->all())->toBe(['WalletOpened']);
})->group('lumen');

it('returns a null balance for an unknown wallet', function () {
    /** @var LumenTestCase $this */
    /** @var QueryBus $queries */
    $queries = $this->fireflyContext()->get(QueryBus::class);

    expect($queries->ask(new GetBalance('wlt-does-not-exist')))->toBeNull();
})->group('lumen');
