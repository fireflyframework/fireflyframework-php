<?php

declare(strict_types=1);

use Firefly\Eda\Bus\SubscriberRegistry;
use Firefly\Eda\Consumer\ConsumerLoop;
use Firefly\Eda\Consumer\ConsumerOptions;
use Firefly\Eda\EventEnvelope;
use Firefly\Eda\Postgres\Outbox\OutboxRelay;
use Firefly\Eda\Postgres\Outbox\OutboxRow;
use Firefly\Eda\Postgres\Outbox\OutboxSchema;
use Firefly\Eda\Postgres\PostgresEventConsumer;
use Firefly\Eda\Postgres\PostgresEventPublisher;
use Firefly\Eda\Postgres\Tests\Fixtures\SpyDownstreamPublisher;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Orchestra\Testbench\TestCase;

uses(TestCase::class);

/**
 * Real Postgres outbox round-trip. Env-gated (NOT a hard CI gate — the PHP ecosystem's PG test infra is thin):
 * requires pdo_pgsql + FIREFLY_PG_DSN (host=…;port=…;dbname=…;user=…;password=…). It proves, end-to-end on real
 * pgsql, the four things sqlite cannot: (1) the outbox row is invisible cross-session until the writer's tx COMMITS
 * (genuine same-tx) and the in-tx pg_notify wakes a separate LISTEN session on commit; (2) the TERMINAL in-process
 * consumer delivers each committed row EXACTLY once with FOR UPDATE SKIP LOCKED + ack->PUBLISHED and NEVER grows the
 * outbox (B1 — no EventPublisher call, no re-insert); (3) crash-safety — a handler throw nacks back to PENDING
 * (attempts+1), a fresh consumer resumes the durable status window (M3), and past max_attempts the row goes FAILED;
 * (4) the OPTIONAL relay forwards a fresh PENDING row to a DISTINCT downstream broker (a spy, NEVER
 * PostgresEventPublisher) and marks it PUBLISHED, while the ctor HARD-REFUSES a PostgresEventPublisher downstream.
 * Every payload/headers assertion decodes the jsonb/json column and compares the MAP (never a raw-string equality,
 * which pgsql key/whitespace normalisation would false-fail).
 *
 * @return array<string, mixed> a Laravel pgsql connection config parsed from FIREFLY_PG_DSN
 */
function outbox_pg_config(): array
{
    $parts = [];
    foreach (explode(';', (string) getenv('FIREFLY_PG_DSN')) as $kv) {
        [$key, $value] = array_pad(explode('=', $kv, 2), 2, '');
        $parts[$key] = $value;
    }

    return [
        'driver' => 'pgsql',
        'host' => $parts['host'] ?? '127.0.0.1',
        'port' => $parts['port'] ?? '5432',
        'database' => $parts['dbname'] ?? 'postgres',
        'username' => $parts['user'] ?? 'postgres',
        'password' => $parts['password'] ?? '',
    ];
}

/** Decode a json/jsonb column value to a comparable map (jsonb-aware — never a raw-string compare). */
function outbox_decode(mixed $column): mixed
{
    return json_decode(is_string($column) ? $column : '', true);
}

it('same-tx write -> terminal in-process consumer, crash-safety, plus optional relay -> distinct downstream broker', function () {
    // Two independent pgsql sessions so the writer's in-tx pg_notify reaches the consumer's LISTEN session.
    config([
        'database.connections.outbox_writer' => outbox_pg_config(),
        'database.connections.outbox_consumer' => outbox_pg_config(),
    ]);
    $channel = 'firefly_eda_test_'.bin2hex(random_bytes(4));

    Schema::connection('outbox_writer')->dropIfExists(OutboxSchema::TABLE);
    Schema::connection('outbox_writer')->create(OutboxSchema::TABLE, fn (Blueprint $t) => OutboxSchema::blueprint($t));

    $writer = DB::connection('outbox_writer');
    $consumerConn = DB::connection('outbox_consumer');
    $options = new ConsumerOptions(maxMessages: 1, timeLimit: 20, pollTimeoutMs: 1000);

    // ---- (1)+(2) SAME-TX WRITE + LISTEN/NOTIFY on COMMIT ----
    $consumer = new PostgresEventConsumer($consumerConn, $channel);
    $consumer->subscribe(['*']); // LISTEN <channel> on the consumer session BEFORE the writer commits

    $writer->transaction(function () use ($writer, $channel): void {
        (new PostgresEventPublisher($writer, $channel, true))->publish('orders', 'order.created', ['id' => 7], ['x-a' => 'b']);
        // Still inside the writer's tx: the row is NOT yet visible to the separate consumer session.
        expect(DB::connection('outbox_consumer')->table(OutboxSchema::TABLE)->count())->toBe(0);
    });

    // Committed: exactly one PENDING row is now visible cross-session.
    expect($consumerConn->table(OutboxSchema::TABLE)->where('status', OutboxSchema::STATUS_PENDING)->count())->toBe(1);

    // ---- (3) TERMINAL PATH: deliver EXACTLY once, ack->PUBLISHED, outbox does NOT grow (no re-insert) ----
    $registry = new SubscriberRegistry;
    $seen = [];
    $registry->subscribe('order.*', function (EventEnvelope $e) use (&$seen): void {
        $seen[] = $e->payload;
    });

    $processed = (new ConsumerLoop)->run($consumer, fn (EventEnvelope $e) => $registry->deliver($e), $options);

    expect($processed)->toBe(1)
        ->and($seen)->toHaveCount(1)
        ->and($seen[0])->toBe(['id' => 7])                                                    // jsonb payload decoded to the map
        ->and($consumerConn->table(OutboxSchema::TABLE)->count())->toBe(1)                    // outbox did NOT grow
        ->and($consumerConn->table(OutboxSchema::TABLE)->where('status', OutboxSchema::STATUS_PUBLISHED)->whereNotNull('processed_at')->count())->toBe(1);

    $row = $consumerConn->table(OutboxSchema::TABLE)->first();
    if ($row === null) {
        throw new RuntimeException('Expected the committed outbox row.');
    }
    // jsonb-aware column assertions (NOT raw-string equality — pgsql json normalisation would false-fail that).
    expect(outbox_decode($row->payload))->toBe(['id' => 7])
        ->and(outbox_decode($row->headers))->toBe(['x-a' => 'b']);

    // ---- (4) CRASH-SAFETY: handler throw -> nack -> still PENDING; fresh consumer resumes; past max -> FAILED ----
    (new PostgresEventPublisher($writer, $channel, true))->publish('orders', 'order.updated', ['id' => 8]);
    $throwingConsumer = new PostgresEventConsumer($consumerConn, $channel);
    $throwingConsumer->subscribe(['*']);
    (new ConsumerLoop)->run($throwingConsumer, function (): void {
        throw new RuntimeException('handler boom');
    }, $options);

    $nacked = $consumerConn->table(OutboxSchema::TABLE)->where('event_type', 'order.updated')->first();
    if ($nacked === null) {
        throw new RuntimeException('Expected the nacked row to remain.');
    }
    expect($nacked->status)->toBe(OutboxSchema::STATUS_PENDING)->and(OutboxRow::asInt($nacked->attempts))->toBe(1);

    // A fresh consumer resumes the still-PENDING row (durable status window, M3) and marks it PUBLISHED.
    $resumeConsumer = new PostgresEventConsumer($consumerConn, $channel);
    $resumeConsumer->subscribe(['*']);
    $resumed = 0;
    (new ConsumerLoop)->run($resumeConsumer, function () use (&$resumed): void {
        $resumed++;
    }, $options);

    $resolved = $consumerConn->table(OutboxSchema::TABLE)->where('event_type', 'order.updated')->first();
    if ($resolved === null) {
        throw new RuntimeException('Expected the resumed row to remain.');
    }
    expect($resumed)->toBe(1)->and($resolved->status)->toBe(OutboxSchema::STATUS_PUBLISHED);

    // Past max_attempts -> FAILED (maxAttempts=1: the first nack fails it).
    (new PostgresEventPublisher($writer, $channel, true))->publish('orders', 'order.cancelled', ['id' => 9]);
    $failingConsumer = new PostgresEventConsumer($consumerConn, $channel, 1);
    $failingConsumer->subscribe(['*']);
    (new ConsumerLoop)->run($failingConsumer, function (): void {
        throw new RuntimeException('handler boom');
    }, $options);

    $failed = $consumerConn->table(OutboxSchema::TABLE)->where('event_type', 'order.cancelled')->first();
    if ($failed === null) {
        throw new RuntimeException('Expected the failed row to remain.');
    }
    expect($failed->status)->toBe(OutboxSchema::STATUS_FAILED)->and(OutboxRow::asInt($failed->attempts))->toBe(1);

    // ---- (5) OPTIONAL RELAY -> DISTINCT downstream broker (spy), FOR UPDATE SKIP LOCKED on pgsql ----
    (new PostgresEventPublisher($writer, $channel, true))->publish('billing', 'invoice.raised', ['id' => 10], ['x-c' => 'd']);
    $spy = new SpyDownstreamPublisher;
    $relayed = (new OutboxRelay($writer, $spy, batchSize: 50, maxAttempts: 3, useSkipLocked: true))->relayBatch();

    expect($relayed)->toBe(1)
        ->and($spy->published)->toHaveCount(1)
        ->and($spy->published[0]->eventType)->toBe('invoice.raised')
        ->and($spy->published[0]->payload)->toBe(['id' => 10])                                // decoded map, not raw jsonb text
        ->and($spy->published[0]->headers)->toBe(['x-c' => 'd'])
        ->and($writer->table(OutboxSchema::TABLE)->where('event_type', 'invoice.raised')->value('status'))->toBe(OutboxSchema::STATUS_PUBLISHED);

    // B1: the relay ctor HARD-REFUSES a PostgresEventPublisher downstream (would re-insert PENDING rows -> loop).
    expect(fn () => new OutboxRelay($writer, new PostgresEventPublisher($writer, $channel)))
        ->toThrow(LogicException::class, 'PostgresEventPublisher');

    Schema::connection('outbox_writer')->dropIfExists(OutboxSchema::TABLE);
    $writer->disconnect();
    $consumerConn->disconnect();
})->skip(
    ! extension_loaded('pdo_pgsql') || getenv('FIREFLY_PG_DSN') === false,
    'Set FIREFLY_PG_DSN (and pdo_pgsql) to run the @group integration Postgres outbox round-trip.',
)->group('integration');
