<?php

declare(strict_types=1);

use Firefly\Eda\Consumer\ConsumerLoop;
use Firefly\Eda\Consumer\ConsumerOptions;
use Firefly\Eda\Consumer\EnvelopeSink;
use Firefly\Eda\Consumer\ReceivedEnvelope;
use Firefly\Eda\EventEnvelope;
use Firefly\Eda\Tests\Fixtures\ScriptedEventConsumer;
use Psr\Log\AbstractLogger;

it('polls up to max-messages, delivers each to the sink, acks, and stops', function () {
    $e1 = new EventEnvelope('user.created', 'users', ['id' => 1]);
    $e2 = new EventEnvelope('user.created', 'users', ['id' => 2]);
    $consumer = new ScriptedEventConsumer([$e1, $e2]);
    $seen = [];

    $count = (new ConsumerLoop)->run(
        $consumer,
        function (EventEnvelope $envelope) use (&$seen): void {
            $seen[] = $envelope->payload['id'];
        },
        new ConsumerOptions(maxMessages: 2, pollTimeoutMs: 10),
    );

    expect($count)->toBe(2)
        ->and($seen)->toBe([1, 2])
        ->and($consumer->started)->toBeTrue()
        ->and($consumer->stopped)->toBeTrue()
        ->and($consumer->acked)->toHaveCount(2)
        ->and($consumer->nacked)->toHaveCount(0);
});

it('nacks (requeue) when the sink throws — an exhausted-no-DLQ handler', function () {
    $consumer = new ScriptedEventConsumer([new EventEnvelope('x', 'd', [])]);

    $count = (new ConsumerLoop)->run(
        $consumer,
        function (): void {
            throw new RuntimeException('handler exhausted');
        },
        new ConsumerOptions(maxMessages: 1, pollTimeoutMs: 10),
    );

    expect($count)->toBe(1)
        ->and($consumer->acked)->toHaveCount(0)
        ->and($consumer->nacked)->toHaveCount(1);
});

/*
 * ONE MALFORMED BODY USED TO KILL THE WORKER. Every adapter deserialised inside poll(), and poll() sat outside
 * the loop's try/catch — so the most ordinary failure on a cross-language topic (a producer in another
 * language writing a shape this serializer does not know) was not a dead letter but a crash, and the
 * supervisor restarted the process onto the same record for ever. An adapter now hands the loop a POISON
 * record — the raw bytes, the failure and the destination — and the loop nacks it without requeue, which is
 * the broker's dead-letter path, and keeps polling.
 */
it('dead-letters a poison record (nack without requeue) and keeps polling instead of dying', function () {
    $good = new EventEnvelope('user.created', 'users', ['id' => 2]);
    $consumer = new ScriptedEventConsumer([
        ReceivedEnvelope::poison('{not json', 'tag-1', new RuntimeException('Decoded JSON is not a valid EventEnvelope shape.'), 'users'),
        $good,
    ]);
    $seen = [];

    $count = (new ConsumerLoop)->run(
        $consumer,
        function (EventEnvelope $envelope) use (&$seen): void {
            $seen[] = $envelope->payload['id'];
        },
        new ConsumerOptions(maxMessages: 2, pollTimeoutMs: 10),
    );

    expect($count)->toBe(2)
        ->and($seen)->toBe([2])
        ->and($consumer->nacked)->toBe(['tag-1'])
        ->and($consumer->nackedRequeue)->toBe([false])
        ->and($consumer->acked)->toHaveCount(1);
});

it('logs a poison record with its destination and the failure, never the sink', function () {
    $log = new class extends AbstractLogger
    {
        /** @var list<array{0: string, 1: array<mixed>}> */
        public array $records = [];

        public function log($level, string|Stringable $message, array $context = []): void
        {
            $this->records[] = [(string) $message, $context];
        }
    };
    $consumer = new ScriptedEventConsumer([
        ReceivedEnvelope::poison('<xml/>', 'tag-1', new RuntimeException('not json'), 'orders'),
    ]);

    (new ConsumerLoop($log))->run($consumer, static fn () => throw new LogicException('the sink must not see a poison record'), new ConsumerOptions(maxMessages: 1, pollTimeoutMs: 10));

    expect($log->records)->toHaveCount(1)
        ->and($log->records[0][1]['destination'])->toBe('orders')
        ->and($log->records[0][1]['error'])->toBe('not json');
});

/*
 * THE SINK IS A PORT. The loop delivered to a callable that ConsumeEventsCommand always built over the
 * SubscriberRegistry — the #[EventListener] beans — so an application whose events must go somewhere else
 * (a command bus, a projector, a relay) had to write its own consumer command, its own loop, its own signal
 * handling and its own dead-letter path. EnvelopeSink is the seam that lets it keep only the delivery.
 */
it('delivers to an EnvelopeSink implementation as readily as to a callable', function () {
    $sink = new class implements EnvelopeSink
    {
        /** @var list<string> */
        public array $types = [];

        public function handle(EventEnvelope $envelope): void
        {
            $this->types[] = $envelope->eventType;
        }
    };
    $consumer = new ScriptedEventConsumer([new EventEnvelope('a', 'd'), new EventEnvelope('b', 'd')]);

    (new ConsumerLoop)->run($consumer, $sink, new ConsumerOptions(maxMessages: 2, pollTimeoutMs: 10));

    expect($sink->types)->toBe(['a', 'b'])
        ->and($consumer->acked)->toHaveCount(2);
});
