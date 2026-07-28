<?php

declare(strict_types=1);

namespace Lumen\Tests\Integration;

use Firefly\Cqrs\Command\CommandBus;
use Firefly\Cqrs\Query\QueryBus;
use Firefly\Eda\Postgres\Outbox\OutboxSchema;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Lumen\Application\Command\Deposit;
use Lumen\Application\Command\OpenWallet;
use Lumen\Application\Command\Transfer;
use Lumen\Application\Query\GetBalance;
use Lumen\Domain\Currency;

uses(OutboxPostgresIntegrationTestCase::class);

/**
 * Real-Postgres outbox round-trip for the wallet sample. Env-gated exactly like
 * packages/eda-postgres/tests/Integration/PostgresOutboxRoundTripTest — a manual skip on missing pdo_pgsql /
 * FIREFLY_PG_DSN (NO RequiresDocker; Docker is optional) plus ->group('integration') so the default `composer test`
 * (phpunit.xml.dist excludes the integration group) never runs it. Without a DSN here it SKIPS cleanly.
 *
 * On a real pgsql target it proves the whole stack on the driver the outbox is built for: a committed #[Transactional]
 * transfer writes its FundsWithdrawn domain event to firefly_eda_outbox IN the aggregate's transaction (same-tx,
 * atomic), and the terminal in-process consumer (firefly:eda:consume, postgres mode) claims that PENDING row, drives
 * the #[EventListener] handlers, and marks it PUBLISHED — without ever re-inserting (the outbox never grows).
 */
it('writes the transfer outbox row atomically on real Postgres and the consumer marks it PUBLISHED', function () {
    /** @var OutboxPostgresIntegrationTestCase $this */
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

    // The committed transfer's FundsWithdrawn event landed in the outbox same-tx (PENDING), and the money moved.
    expect(DB::table(OutboxSchema::TABLE)
        ->where('event_type', 'FundsWithdrawn')
        ->where('status', OutboxSchema::STATUS_PENDING)
        ->count())->toBe(1)
        ->and($queries->ask(new GetBalance($src)))->toBe(6000)
        ->and($queries->ask(new GetBalance($dst)))->toBe(4000);

    // The terminal in-process consumer claims committed PENDING rows (FOR UPDATE SKIP LOCKED), delivers them, and
    // marks them PUBLISHED — with no EventPublisher call, so the outbox does not grow.
    $total = DB::table(OutboxSchema::TABLE)->count();
    Artisan::call('firefly:eda:consume', ['--max-messages' => $total, '--time-limit' => 20, '--poll-timeout' => 1000]);

    expect(DB::table(OutboxSchema::TABLE)->where('status', OutboxSchema::STATUS_PENDING)->count())->toBe(0)
        ->and(DB::table(OutboxSchema::TABLE)
            ->where('event_type', 'FundsWithdrawn')
            ->where('status', OutboxSchema::STATUS_PUBLISHED)
            ->count())->toBe(1)
        ->and(DB::table(OutboxSchema::TABLE)->count())->toBe($total); // never re-inserted
})->skip(
    ! extension_loaded('pdo_pgsql') || getenv('FIREFLY_PG_DSN') === false,
    'Set FIREFLY_PG_DSN (and pdo_pgsql) to run the @group integration lumen Postgres outbox round-trip.',
)->group('integration');
