<?php

declare(strict_types=1);

namespace Lumen\Tests\Application;

use Firefly\Cqrs\Command\CommandBus;
use Firefly\Cqrs\Query\QueryBus;
use Lumen\Application\Command\OpenWallet;
use Lumen\Application\Query\GetBalance;
use Lumen\Application\Query\GetLedger;
use Lumen\Domain\Currency;
use Lumen\Tests\LumenTestCase;

uses(LumenTestCase::class);

it('reads a zero balance and an empty ledger for a freshly opened wallet', function () {
    /** @var LumenTestCase $this */
    /** @var CommandBus $commands */
    $commands = $this->fireflyContext()->get(CommandBus::class);
    /** @var QueryBus $queries */
    $queries = $this->fireflyContext()->get(QueryBus::class);

    /** @var string $walletId */
    $walletId = $commands->send(new OpenWallet('owner-q', Currency::USD));

    // The ledger projector is S5; until it runs, GetLedger reads whatever is in ledger_entries — nothing yet.
    expect($queries->ask(new GetBalance($walletId)))->toBe(0);
    expect($queries->ask(new GetLedger($walletId)))->toBe([]);
})->group('lumen');

it('returns a null balance for an unknown wallet', function () {
    /** @var LumenTestCase $this */
    /** @var QueryBus $queries */
    $queries = $this->fireflyContext()->get(QueryBus::class);

    expect($queries->ask(new GetBalance('wlt-does-not-exist')))->toBeNull();
})->group('lumen');
