<?php

declare(strict_types=1);

namespace Lumen\Tests\Integration;

use Firefly\Cqrs\Command\CommandBus;
use Firefly\Cqrs\Query\QueryBus;
use Firefly\Eda\Database\Outbox\OutboxSchema;
use Firefly\Testing\Integration\IntegrationDsn;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Lumen\Application\Command\Deposit;
use Lumen\Application\Command\OpenWallet;
use Lumen\Application\Query\GetBalance;
use Lumen\Domain\Currency;
use Lumen\Domain\LedgerEntry;

uses(OutboxDatabaseIntegrationTestCase::class);

/**
 * The sample's portable outbox on a REAL server per lane — PostgreSQL, MySQL, MariaDB, SQL Server (CI) and a SQLite
 * file: the wallet and its domain events commit together, the claim strategy `auto` picks for that server leases the
 * rows, and the projector runs only when the consumer delivers them. Reads happen after commit, from this process.
 */
it('writes the outbox rows in the aggregate transaction and delivers them with a consumer', function (string $lane) {
    /** @var OutboxDatabaseIntegrationTestCase $this */
    /** @var CommandBus $commands */
    $commands = $this->fireflyContext()->get(CommandBus::class);
    /** @var QueryBus $queries */
    $queries = $this->fireflyContext()->get(QueryBus::class);

    /** @var string $id */
    $id = $commands->send(new OpenWallet('owner-it', Currency::EUR));
    $commands->send(new Deposit($id, 2500));

    expect(DB::connection()->getName())->toBe('lumen-it')
        ->and(DB::table(OutboxSchema::TABLE)->where('status', OutboxSchema::STATUS_PENDING)->orderBy('id')->pluck('event_type')->all())
        ->toBe(['WalletOpened', 'FundsDeposited'])
        ->and($queries->ask(new GetBalance($id)))->toBe(2500);

    $exit = Artisan::call('firefly:eda:consume', ['--max-messages' => 2, '--time-limit' => 20, '--poll-timeout' => 500]);

    expect($exit)->toBe(0)
        ->and(DB::table(OutboxSchema::TABLE)->where('status', OutboxSchema::STATUS_PENDING)->count())->toBe(0)
        ->and(LedgerEntry::query()->where('wallet_id', $id)->orderBy('id')->pluck('event_type')->all())
        ->toBe(['WalletOpened', 'FundsDeposited']);
})->with(IntegrationDsn::lanes())->group('integration');
