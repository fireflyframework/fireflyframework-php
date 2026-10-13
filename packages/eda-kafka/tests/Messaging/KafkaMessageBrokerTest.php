<?php

declare(strict_types=1);

use Firefly\Eda\Kafka\Messaging\KafkaMessageBroker;
use Firefly\Eda\Kafka\Messaging\KafkaRecordClient;
use Firefly\Eda\Kafka\Tests\Fixtures\FakeKafkaRecordClient;
use Firefly\Messaging\Container\LinearBackOff;
use Firefly\Messaging\Container\ListenerContainerBroker;
use Firefly\Messaging\Container\ListenerInvoker;
use Firefly\Messaging\Container\ListenerOptions;
use Firefly\Messaging\Container\RetryPolicy;
use Firefly\Messaging\Exception\MessagingException;
use Firefly\Messaging\Message;

/**
 * @return array{0: KafkaMessageBroker, 1: ArrayObject<string, FakeKafkaRecordClient>, 2: FakeKafkaRecordClient}
 */
function w4KafkaBroker(int $maxAttempts = 1, bool $deadLetters = true): array
{
    /** @var ArrayObject<string, FakeKafkaRecordClient> $consumers */
    $consumers = new ArrayObject;
    $producer = new FakeKafkaRecordClient;
    $broker = new KafkaMessageBroker(
        static function (string $group) use ($consumers): KafkaRecordClient {
            return $consumers[$group] ??= new FakeKafkaRecordClient;
        },
        $producer,
        new RetryPolicy($maxAttempts, new LinearBackOff(0.0)),
        new ListenerInvoker(static function (float $seconds): void {}),
        deadLetterEnabled: $deadLetters,
    );

    return [$broker, $consumers, $producer];
}

/**
 * The consumer the broker built for $group; failing loudly, rather than answering null, when it built none.
 *
 * @param  ArrayObject<string, FakeKafkaRecordClient>  $consumers
 */
function w4KafkaConsumer(ArrayObject $consumers, string $group): FakeKafkaRecordClient
{
    $consumer = $consumers[$group] ?? null;
    if ($consumer === null) {
        throw new LogicException("The broker built no consumer for group [{$group}].");
    }

    return $consumer;
}

it('is a listener-container broker that publishes value, key and headers, and flushes', function () {
    [$broker, , $producer] = w4KafkaBroker();

    $broker->publish('orders', 'bytes', 'k-1', ['x-trace' => 't']);

    expect($broker)->toBeInstanceOf(ListenerContainerBroker::class)
        ->and($producer->produced)->toBe([['topic' => 'orders', 'value' => 'bytes', 'key' => 'k-1', 'headers' => ['x-trace' => 't']]])
        ->and($producer->flushes)->toBe(1);
});

it('builds one consumer per group on the first poll, subscribed to that group\'s topics', function () {
    [$broker, $consumers] = w4KafkaBroker();
    $noop = static function (Message $message): void {};
    $broker->subscribeListener('a', $noop, 'g1', new ListenerOptions);
    $broker->subscribeListener('b', $noop, 'g2', new ListenerOptions);
    $broker->subscribeListener('c', $noop, 'g1', new ListenerOptions);
    $broker->subscribe('d', $noop);

    expect($broker->poll(10))->toBeFalse()
        ->and(w4KafkaConsumer($consumers, 'g1')->subscriptions)->toBe([['a', 'c']])
        ->and(w4KafkaConsumer($consumers, 'g2')->subscriptions)->toBe([['b']])
        ->and(w4KafkaConsumer($consumers, 'firefly')->subscriptions)->toBe([['d']])
        ->and(fn () => $broker->subscribeListener('e', $noop, 'g1', new ListenerOptions))->toThrow(MessagingException::class, 'already polling');
});

it('delivers a record to the listeners of its topic and commits it afterwards', function () {
    [$broker, $consumers] = w4KafkaBroker();
    $seen = [];
    $broker->subscribeListener('orders', function (Message $message) use (&$seen): void {
        $seen[] = [$message->topic, $message->value, $message->key, $message->headers];
    }, null, new ListenerOptions);
    $broker->poll(1);
    w4KafkaConsumer($consumers, 'firefly')->incoming[] = FakeKafkaRecordClient::record('orders', 'v', 'k', ['h' => '1'], 7);

    expect($broker->poll(10))->toBeTrue()
        ->and($seen)->toBe([['orders', 'v', 'k', ['h' => '1']]])
        ->and(w4KafkaConsumer($consumers, 'firefly')->committed)->toHaveCount(1);
});

it('retries under the policy, then dead-letters to <topic>.DLT with provenance headers, then commits', function () {
    [$broker, $consumers, $producer] = w4KafkaBroker(maxAttempts: 3);
    $calls = 0;
    $broker->subscribeListener('orders', function () use (&$calls): void {
        $calls++;

        throw new RuntimeException('handler down');
    }, null, new ListenerOptions);
    $broker->poll(1);
    w4KafkaConsumer($consumers, 'firefly')->incoming[] = FakeKafkaRecordClient::record('orders', 'v', 'k', ['h' => '1'], 42);

    $broker->poll(10);

    expect($calls)->toBe(3)
        ->and($producer->produced)->toHaveCount(1)
        ->and($producer->produced[0]['topic'])->toBe('orders.DLT')
        ->and($producer->produced[0]['value'])->toBe('v')
        ->and($producer->produced[0]['key'])->toBe('k')
        ->and($producer->produced[0]['headers'])->toMatchArray([
            'h' => '1',
            'x-original-topic' => 'orders',
            'x-exception' => 'RuntimeException',
            'x-exception-message' => 'handler down',
            'x-delivery-attempts' => '3',
            'x-dlt-reason' => 'RuntimeException',
            'x-dlt-source-topic' => 'orders',
            'x-dlt-source-offset' => '42',
        ])
        ->and(w4KafkaConsumer($consumers, 'firefly')->committed)->toHaveCount(1);
});

it("routes to the listener's own dead-letter topic and honours its retries", function () {
    [$broker, $consumers, $producer] = w4KafkaBroker(maxAttempts: 5);
    $calls = 0;
    $broker->subscribeListener('orders', function () use (&$calls): void {
        $calls++;

        throw new RuntimeException('no');
    }, null, new ListenerOptions(2, new LinearBackOff(0.0), 'orders.parked'));
    $broker->poll(1);
    w4KafkaConsumer($consumers, 'firefly')->incoming[] = FakeKafkaRecordClient::record('orders', 'v');

    $broker->poll(10);

    expect($calls)->toBe(2)->and($producer->produced[0]['topic'])->toBe('orders.parked');
});

it('logs and skips after the last attempt when dead letters are off', function () {
    [$broker, $consumers, $producer] = w4KafkaBroker(deadLetters: false);
    $broker->subscribeListener('orders', static function (): void {
        throw new RuntimeException('no');
    }, null, new ListenerOptions);
    $broker->poll(1);
    w4KafkaConsumer($consumers, 'firefly')->incoming[] = FakeKafkaRecordClient::record('orders', 'v');

    $broker->poll(10);

    expect($producer->produced)->toBe([])->and(w4KafkaConsumer($consumers, 'firefly')->committed)->toHaveCount(1);
});

it('leaves the offset uncommitted when the dead letter cannot be produced', function () {
    [$broker, $consumers, $producer] = w4KafkaBroker();
    $broker->subscribeListener('orders', static function (): void {
        throw new RuntimeException('no');
    }, null, new ListenerOptions);
    $broker->poll(1);
    w4KafkaConsumer($consumers, 'firefly')->incoming[] = FakeKafkaRecordClient::record('orders', 'v');
    $producer->produceFailure = new RuntimeException('broker down');

    expect(fn () => $broker->poll(10))->toThrow(MessagingException::class, 'left uncommitted')
        ->and(w4KafkaConsumer($consumers, 'firefly')->committed)->toBe([]);
});

it('closes every consumer and flushes the producer on stop', function () {
    [$broker, $consumers, $producer] = w4KafkaBroker();
    $broker->subscribeListener('orders', static function (): void {}, null, new ListenerOptions);
    $broker->poll(1);

    $broker->stop();

    expect(w4KafkaConsumer($consumers, 'firefly')->closed)->toBeTrue()->and($producer->flushes)->toBe(1);
});
