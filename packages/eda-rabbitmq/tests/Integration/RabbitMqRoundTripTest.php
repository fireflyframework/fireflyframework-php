<?php

declare(strict_types=1);

use Firefly\Config\Config;
use Firefly\Eda\Bus\SubscriberRegistry;
use Firefly\Eda\Consumer\ConsumerLoop;
use Firefly\Eda\Consumer\ConsumerOptions;
use Firefly\Eda\EventEnvelope;
use Firefly\Eda\JsonSerializer;
use Firefly\Eda\Rabbitmq\RabbitMqConnectionFactory;
use Firefly\Eda\Rabbitmq\RabbitMqEventConsumer;
use Firefly\Eda\Rabbitmq\RabbitMqEventPublisher;
use Firefly\Eda\Rabbitmq\Tests\Support\RabbitMqIntegrationTestCase;
use Illuminate\Config\Repository;
use PhpAmqpLib\Message\AMQPMessage;

uses(RabbitMqIntegrationTestCase::class);

/** @return Config a Config over firefly.eda.rabbitmq.* parsed from FIREFLY_RABBITMQ_DSN */
function rabbitTestConfig(): Config
{
    $parts = [];
    foreach (explode(';', (string) getenv('FIREFLY_RABBITMQ_DSN')) as $kv) {
        [$key, $value] = array_pad(explode('=', $kv, 2), 2, '');
        $parts[$key] = $value;
    }

    return new Config(new Repository([
        'firefly' => [
            'eda' => [
                'rabbitmq' => [
                    'host' => $parts['host'] ?? '127.0.0.1',
                    'port' => (int) ($parts['port'] ?? 5672),
                    'user' => $parts['user'] ?? 'guest',
                    'password' => $parts['password'] ?? 'guest',
                    'vhost' => '/',
                ],
            ],
        ],
    ]));
}

it('round-trips publish -> consume -> handler -> ack against a real RabbitMQ', function () {
    $config = rabbitTestConfig();
    $factory = new RabbitMqConnectionFactory($config);
    $registry = new SubscriberRegistry;
    $queue = 'firefly.eda.test.'.bin2hex(random_bytes(4));

    $received = [];
    $registry->subscribe('order.*', function (EventEnvelope $envelope) use (&$received): void {
        $received[] = $envelope->payload['id'];
    });

    // The consumer's subscribe() declares the topic exchange + a durable work queue and BINDS it to 'order.*'
    // FIRST: AMQP 0-9-1 silently drops a message published to a topic exchange with no bound queue as unroutable,
    // so the queue must exist and be bound before anything is published — otherwise the message below is gone
    // before poll() ever runs.
    $consumer = new RabbitMqEventConsumer($factory, new JsonSerializer, 'firefly.events.test', $queue, 'firefly.events.test.dlx', 10);
    $consumer->subscribe(['order.*']);

    $publisher = new RabbitMqEventPublisher($factory, $registry, new JsonSerializer, 'firefly.events.test');
    $publisher->start();
    $publisher->publish('firefly.events.test/order.created', 'order.created', ['id' => 42]);
    $publisher->stop();

    $processed = (new ConsumerLoop)->run(
        $consumer,
        fn (EventEnvelope $envelope) => $registry->deliver($envelope),
        new ConsumerOptions(maxMessages: 1, timeLimit: 15, pollTimeoutMs: 2000),
    );

    expect($processed)->toBe(1)->and($received)->toBe([42]);
})->skip(getenv('FIREFLY_RABBITMQ_DSN') === false, 'Set FIREFLY_RABBITMQ_DSN to run the @group integration RabbitMQ round-trip.')->group('integration');

it('routes an exhausted retry to the DLX queue', function () {
    $config = rabbitTestConfig();
    $factory = new RabbitMqConnectionFactory($config);
    $queue = 'firefly.eda.test.'.bin2hex(random_bytes(4));
    $exchange = 'firefly.events.test';
    $dlx = 'firefly.events.test.dlx';
    $dlxQueue = $queue.'.dlx';

    // Declare + bind the DLX observer queue BEFORE anything is dead-lettered, so the routed message has
    // somewhere to land. A '#' binding catches everything RabbitMQ dead-letters onto this exchange.
    $setupConnection = $factory->connect();
    $setupChannel = $setupConnection->channel();
    $setupChannel->exchange_declare($dlx, 'topic', false, true, false);
    $setupChannel->queue_declare($dlxQueue, false, true, false, false, false, []);
    $setupChannel->queue_bind($dlxQueue, $dlx, '#');
    $setupChannel->close();
    $setupConnection->close();

    // The consumer's subscribe() declares the work queue (carrying its x-dead-letter-exchange arg) and BINDS it
    // to 'order.*' on the main exchange FIRST: a topic exchange silently drops an unroutable publish, so the
    // queue must exist and be bound before anything is published — otherwise there is nothing left to receive
    // and later nack into the DLX below.
    $consumer = new RabbitMqEventConsumer($factory, new JsonSerializer, $exchange, $queue, $dlx, 10);
    $consumer->subscribe(['order.*']);

    $publisher = new RabbitMqEventPublisher($factory, new SubscriberRegistry, new JsonSerializer, $exchange);
    $publisher->start();
    $publisher->publish($exchange.'/order.created', 'order.created', ['id' => 99]);
    $publisher->stop();

    $received = null;
    $deadline = time() + 15;
    while ($received === null && time() < $deadline) {
        $received = $consumer->poll(2000);
    }
    if ($received === null) {
        throw new RuntimeException('Expected the published message to be received before the deadline.');
    }

    // A handler that always fails, exhausted -> the caller (a retry policy, or here directly the test) dead-letters
    // via nack(requeue: false) instead of the ConsumerLoop default nack(requeue: true) at-least-once retry.
    $consumer->nack($received, false);
    $consumer->stop();

    // Give RabbitMQ a moment to route the dead-lettered message, then inspect the DLX queue directly via basic_get.
    $checkConnection = $factory->connect();
    $checkChannel = $checkConnection->channel();
    $message = null;
    $deadline = time() + 10;
    while ($message === null && time() < $deadline) {
        $message = $checkChannel->basic_get($dlxQueue);
        if ($message === null) {
            usleep(200_000);
        }
    }
    $checkChannel->close();
    $checkConnection->close();

    if (! $message instanceof AMQPMessage) {
        throw new RuntimeException('Expected a dead-lettered message on the DLX queue before the deadline.');
    }

    $decoded = json_decode($message->getBody(), true);
    if (! is_array($decoded) || ! is_array($decoded['payload'] ?? null)) {
        throw new RuntimeException('Expected the dead-lettered message body to decode to an EventEnvelope array.');
    }

    expect($decoded['payload']['id'] ?? null)->toBe(99);
})->skip(getenv('FIREFLY_RABBITMQ_DSN') === false, 'Set FIREFLY_RABBITMQ_DSN to run the @group integration RabbitMQ round-trip.')->group('integration');
