<?php

declare(strict_types=1);

use Firefly\Eda\Bus\SubscriberRegistry;
use Firefly\Eda\EventEnvelope;
use Firefly\Eda\Postgres\Outbox\OutboxSchema;
use Firefly\Eda\Postgres\PostgresEventPublisher;
use Firefly\Eda\Tracing\EdaTracing;
use Firefly\Testing\FireflyDatabaseTestCase;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

uses(FireflyDatabaseTestCase::class);

beforeEach(function () {
    Schema::create(OutboxSchema::TABLE, fn (Blueprint $t) => OutboxSchema::blueprint($t));
});

/**
 * The producer side of the trace, on the outbox row — the RabbitMqPublisherTracingTest sibling, and the one
 * adapter where the producer span and the durable record are genuinely atomic: the INSERT now happens INSIDE
 * EdaTracing::tracePublish(), so the traceparent that span produced is on the row that commits with the
 * aggregate. The relay and the in-process consumer already hand a row's headers to SubscriberRegistrySink, which
 * continues the trace, so this closes the loop end to end.
 */
function postgresStampingTracing(): EdaTracing
{
    return new class implements EdaTracing
    {
        public function tracePublish(string $destination, string $eventType, array $headers, callable $send): void
        {
            $send([...$headers, 'traceparent' => '00-4bf92f3577b34da6a3ce929d0e0e4736-00f067aa0ba902b7-01', 'tracestate' => 'firefly=1']);
        }

        public function traceConsume(EventEnvelope $envelope, callable $deliver): void
        {
            $deliver($envelope);
        }
    };
}

it('writes the traceparent into the outbox row headers', function () {
    (new PostgresEventPublisher(DB::connection(), new SubscriberRegistry, 'firefly_eda_events', false, postgresStampingTracing()))
        ->publish('order.events', 'order.created', ['id' => 9], ['x-correlation-id' => 'c1']);

    $row = DB::table(OutboxSchema::TABLE)->where('destination', 'order.events')->first();
    $headers = $row?->headers;

    if (! is_string($headers)) {
        throw new RuntimeException('Expected one outbox row carrying a headers column.');
    }

    expect(json_decode($headers, true, 512, JSON_THROW_ON_ERROR))->toBe([
        'x-correlation-id' => 'c1',
        'traceparent' => '00-4bf92f3577b34da6a3ce929d0e0e4736-00f067aa0ba902b7-01',
        'tracestate' => 'firefly=1',
    ])
        // transaction_id is derived INSIDE the closure too, so it is still the correlation id and never the
        // traceparent — the relay's idempotency key must not change just because a request was traced.
        ->and($row->transaction_id)->toBe('c1');
});

it('writes exactly the row it wrote before when no tracing is given', function () {
    (new PostgresEventPublisher(DB::connection(), new SubscriberRegistry))
        ->publish('order.events', 'order.created', ['id' => 9], ['x-correlation-id' => 'c1']);

    expect(DB::table(OutboxSchema::TABLE)
        ->where('headers', json_encode(['x-correlation-id' => 'c1']))
        ->where('transaction_id', 'c1')
        ->count())->toBe(1);
});
