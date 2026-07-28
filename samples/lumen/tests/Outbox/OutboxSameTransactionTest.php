<?php

declare(strict_types=1);

namespace Lumen\Tests\Outbox;

use Firefly\Cqrs\Command\CommandBus;
use Firefly\Cqrs\Exception\CommandProcessingException;
use Firefly\Cqrs\Query\QueryBus;
use Firefly\Eda\Postgres\Outbox\OutboxSchema;
use Illuminate\Support\Facades\DB;
use Lumen\Application\Command\Deposit;
use Lumen\Application\Command\OpenWallet;
use Lumen\Application\Command\Transfer;
use Lumen\Application\Query\GetBalance;
use Lumen\Domain\Currency;

uses(OutboxPostgresTestCase::class);

// Gate on firefly/eda-postgres actually being installed (it is, via lumen's require-dev) — the adapter supplies the
// OutboxSchema/OutboxPreCommitHook the same-tx proof drives. Both tests skip cleanly if it is ever absent.
it('writes the FundsWithdrawn outbox row INSIDE the transfer transaction (present after commit)', function () {
    /** @var OutboxPostgresTestCase $this */
    /** @var CommandBus $commands */
    $commands = $this->fireflyContext()->get(CommandBus::class);
    /** @var QueryBus $queries */
    $queries = $this->fireflyContext()->get(QueryBus::class);

    /** @var string $src */
    $src = $commands->send(new OpenWallet('owner-A', Currency::EUR));
    /** @var string $dst */
    $dst = $commands->send(new OpenWallet('owner-B', Currency::EUR));
    $commands->send(new Deposit($src, 10000));

    $commands->send(new Transfer($src, $dst, 4000));

    // The transfer committed: its FundsWithdrawn domain event was written to the outbox IN the same transaction, so a
    // PENDING row is now visible. FundsWithdrawn is raised ONLY by the transfer's debit leg (never by the open/deposit
    // setup), so this row proves the committed transfer's event reached the outbox via the real autoconfig hook path.
    expect(DB::table(OutboxSchema::TABLE)
        ->where('event_type', 'FundsWithdrawn')
        ->where('status', OutboxSchema::STATUS_PENDING)
        ->count())->toBe(1)
        ->and(DB::table(OutboxSchema::TABLE)
            ->where('event_type', 'FundsDeposited')
            ->where('status', OutboxSchema::STATUS_PENDING)
            ->count())->toBe(2); // the deposit setup + the transfer's credit leg

    // ...and the money actually moved (the tx committed, not merely wrote the outbox row).
    expect($queries->ask(new GetBalance($src)))->toBe(6000)
        ->and($queries->ask(new GetBalance($dst)))->toBe(4000);
})->skip(! class_exists(OutboxSchema::class), 'firefly/eda-postgres is not installed.')->group('lumen');

it('rolls the outbox row back WITH the transfer when the credit leg fails (absent after rollback)', function () {
    /** @var OutboxPostgresTestCase $this */
    /** @var CommandBus $commands */
    $commands = $this->fireflyContext()->get(CommandBus::class);
    /** @var QueryBus $queries */
    $queries = $this->fireflyContext()->get(QueryBus::class);

    // Destination in a DIFFERENT currency: the credit leg throws currency-mismatch AFTER the source was debited, so
    // the whole #[Transactional] transfer rolls back — the outbox INSERT enlisted in that tx must roll back too.
    /** @var string $src */
    $src = $commands->send(new OpenWallet('owner-A', Currency::EUR));
    /** @var string $dst */
    $dst = $commands->send(new OpenWallet('owner-B', Currency::USD));
    $commands->send(new Deposit($src, 10000));

    $rowsBefore = DB::table(OutboxSchema::TABLE)->count();

    expect(fn () => $commands->send(new Transfer($src, $dst, 4000)))
        ->toThrow(CommandProcessingException::class);

    // Genuine same-tx atomicity: the failed transfer added NO outbox rows (its FundsWithdrawn INSERT rolled back with
    // the aggregate — none survives), and NO balance moved. Money can neither vanish nor leak an outbox event.
    expect(DB::table(OutboxSchema::TABLE)->where('event_type', 'FundsWithdrawn')->count())->toBe(0)
        ->and(DB::table(OutboxSchema::TABLE)->count())->toBe($rowsBefore)
        ->and($queries->ask(new GetBalance($src)))->toBe(10000)
        ->and($queries->ask(new GetBalance($dst)))->toBe(0);
})->skip(! class_exists(OutboxSchema::class), 'firefly/eda-postgres is not installed.')->group('lumen');
