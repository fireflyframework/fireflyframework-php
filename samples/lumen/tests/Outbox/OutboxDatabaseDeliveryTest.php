<?php

declare(strict_types=1);

namespace Lumen\Tests\Outbox;

use Firefly\Cqrs\Command\CommandBus;
use Firefly\Eda\Database\DatabaseEventPublisher;
use Firefly\Eda\Database\Outbox\OutboxSchema;
use Firefly\Eda\EventPublisher;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Lumen\Application\Command\Deposit;
use Lumen\Application\Command\OpenWallet;
use Lumen\Domain\Currency;
use Lumen\Domain\LedgerEntry;

uses(OutboxDatabaseTestCase::class);

it('binds the portable outbox writer under firefly.eda.provider=database', function () {
    /** @var OutboxDatabaseTestCase $this */
    expect($this->app()->make(EventPublisher::class))->toBeInstanceOf(DatabaseEventPublisher::class);
})->group('lumen');

it('writes each domain event to the outbox in its transaction and delivers it only when a consumer runs', function () {
    /** @var OutboxDatabaseTestCase $this */
    /** @var CommandBus $commands */
    $commands = $this->fireflyContext()->get(CommandBus::class);

    /** @var string $id */
    $id = $commands->send(new OpenWallet('owner-db', Currency::EUR));
    $commands->send(new Deposit($id, 2500));

    // Committed with the wallet and waiting for a consumer: the projector has not run yet.
    expect(DB::table(OutboxSchema::TABLE)->where('status', OutboxSchema::STATUS_PENDING)->orderBy('id')->pluck('event_type')->all())
        ->toBe(['WalletOpened', 'FundsDeposited'])
        ->and(LedgerEntry::query()->where('wallet_id', $id)->count())->toBe(0);

    $exit = Artisan::call('firefly:eda:consume', ['--max-messages' => 2, '--time-limit' => 10, '--poll-timeout' => 100]);

    expect($exit)->toBe(0)
        ->and(DB::table(OutboxSchema::TABLE)->where('status', OutboxSchema::STATUS_PUBLISHED)->count())->toBe(2)
        ->and(LedgerEntry::query()->where('wallet_id', $id)->orderBy('id')->pluck('event_type')->all())
        ->toBe(['WalletOpened', 'FundsDeposited']);
})->group('lumen');
