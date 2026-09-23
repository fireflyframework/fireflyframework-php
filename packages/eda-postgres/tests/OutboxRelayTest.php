<?php

declare(strict_types=1);

use Firefly\Eda\Bus\SubscriberRegistry;
use Firefly\Eda\Postgres\Outbox\OutboxRelay;
use Firefly\Eda\Postgres\Outbox\OutboxSchema;
use Firefly\Eda\Postgres\PostgresEventPublisher;
use Firefly\Eda\Postgres\Tests\Fixtures\SpyDownstreamPublisher;
use Firefly\Eda\Postgres\Tests\Fixtures\StampingEdaTracing;
use Firefly\Testing\FireflyDatabaseTestCase;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

uses(FireflyDatabaseTestCase::class);

beforeEach(fn () => Schema::create(OutboxSchema::TABLE, fn (Blueprint $t) => OutboxSchema::blueprint($t)));

it('claims PENDING rows, publishes them, and marks them PUBLISHED', function () {
    (new PostgresEventPublisher(DB::connection(), new SubscriberRegistry))->publish('users', 'user.created', ['id' => 1]);
    $spy = new SpyDownstreamPublisher;

    $count = (new OutboxRelay(DB::connection(), $spy))->relayBatch();

    expect($count)->toBe(1)
        ->and($spy->published[0]->eventType)->toBe('user.created')
        ->and(DB::table(OutboxSchema::TABLE)->value('status'))->toBe('PUBLISHED');
});

it('is idempotent — a PUBLISHED row is never re-published', function () {
    (new PostgresEventPublisher(DB::connection(), new SubscriberRegistry))->publish('users', 'user.created', ['id' => 1]);
    $spy = new SpyDownstreamPublisher;
    $relay = new OutboxRelay(DB::connection(), $spy);

    $relay->relayBatch();
    $second = $relay->relayBatch();

    expect($second)->toBe(0)->and($spy->published)->toHaveCount(1);
});

it('increments attempts and marks FAILED past maxAttempts', function () {
    (new PostgresEventPublisher(DB::connection(), new SubscriberRegistry))->publish('users', 'user.created', ['id' => 1]);
    $spy = new SpyDownstreamPublisher;
    $spy->fail = true;
    $relay = new OutboxRelay(DB::connection(), $spy, batchSize: 50, maxAttempts: 2);

    $relay->relayBatch(); // attempt 1
    $relay->relayBatch(); // attempt 2 -> FAILED

    $row = DB::table(OutboxSchema::TABLE)->first();
    if ($row === null) {
        throw new RuntimeException('Expected the seeded outbox row to still exist.');
    }
    expect($row->attempts)->toBe(2)->and($row->status)->toBe('FAILED')
        ->and($row->error_message)->toContain('downstream down');
});

it('REFUSES a PostgresEventPublisher downstream (B1 — no relay self-reference / re-insert loop)', function () {
    expect(fn () => new OutboxRelay(DB::connection(), new PostgresEventPublisher(DB::connection(), new SubscriberRegistry)))
        ->toThrow(LogicException::class, 'PostgresEventPublisher');
});

/**
 * THE HOP THAT BROKE THE TRACE.
 *
 * A claimed row already carries the traceparent the producer span stamped on it when it was INSERTed inside the
 * aggregate's transaction. Forwarding those headers straight to the downstream publisher destroyed it: the
 * downstream adapter publishes through EdaTracing::tracePublish(), which starts its span with no explicit parent
 * — and a relay worker has no current span, so it started a fresh ROOT — then merges its own injected headers
 * LAST, overwriting the row's traceparent. A row published under T1 reached Kafka/RabbitMQ under an unrelated T2
 * and the consumer on the far side continued the wrong trace: the same "trace of half a system" the producer
 * spans exist to prevent, one hop later. The forward now runs inside traceConsume() of an envelope rebuilt from
 * the row, so the downstream producer span is a CHILD of the trace the row carries — the same rule
 * SubscriberRegistrySink applies on the in-process path.
 */
it('relays a row UNDER the trace it carries instead of starting a new one', function () {
    $tracing = new StampingEdaTracing;

    // The producer span at INSERT time — the row commits carrying T1.
    (new PostgresEventPublisher(DB::connection(), new SubscriberRegistry, 'firefly_eda_events', false, $tracing))
        ->publish('users', 'user.created', ['id' => 1], ['x-correlation-id' => 'c1']);

    $stamped = $tracing->published[0]['headers']['traceparent'];
    $spy = new SpyDownstreamPublisher($tracing); // a downstream that publishes through the seam, like the real adapters

    $relayed = (new OutboxRelay(DB::connection(), $spy, tracing: $tracing))->relayBatch();

    $forwarded = $spy->published[0]->headers['traceparent'] ?? null;

    expect($relayed)->toBe(1)
        // The row's own envelope reached the consume seam, which is what gives the hop a parent at all.
        ->and($tracing->consumed[0]->eventType)->toBe('user.created')
        ->and($tracing->consumed[0]->headers['traceparent'])->toBe($stamped)
        // A NEW span (the downstream producer) but the SAME trace: continued, not replaced.
        ->and($forwarded)->not->toBe($stamped)
        ->and(StampingEdaTracing::traceId($forwarded))->toBe(StampingEdaTracing::traceId($stamped))
        ->and(DB::table(OutboxSchema::TABLE)->value('status'))->toBe('PUBLISHED');
});

it('forwards the row unchanged, and marks it PUBLISHED, with no tracing configured', function () {
    (new PostgresEventPublisher(DB::connection(), new SubscriberRegistry))->publish('users', 'user.created', ['id' => 1], ['x-a' => 'b']);
    $spy = new SpyDownstreamPublisher;

    // The NoOp default: byte-for-byte the pre-tracing behaviour, which is what keeps this seam free.
    expect((new OutboxRelay(DB::connection(), $spy))->relayBatch())->toBe(1)
        ->and($spy->published[0]->headers)->toBe(['x-a' => 'b'])
        ->and($spy->published[0]->destination)->toBe('users');
});
