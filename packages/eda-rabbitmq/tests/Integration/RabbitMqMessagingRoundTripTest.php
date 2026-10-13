<?php

declare(strict_types=1);

use Firefly\Config\Config;
use Firefly\Eda\Rabbitmq\Messaging\AmqpDelivery;
use Firefly\Eda\Rabbitmq\Messaging\PhpAmqpLibMessagingChannel;
use Firefly\Eda\Rabbitmq\Messaging\RabbitMqMessageBroker;
use Firefly\Eda\Rabbitmq\RabbitMqConnectionFactory;
use Firefly\Eda\Rabbitmq\Tests\Support\RabbitMqIntegrationTestCase;
use Firefly\Messaging\Container\LinearBackOff;
use Firefly\Messaging\Container\ListenerInvoker;
use Firefly\Messaging\Container\ListenerOptions;
use Firefly\Messaging\Container\RetryPolicy;
use Firefly\Messaging\Message;
use Firefly\Testing\Integration\IntegrationDsn;
use Illuminate\Config\Repository;

uses(RabbitMqIntegrationTestCase::class);

/** @return RabbitMqConnectionFactory over firefly.eda.rabbitmq.* parsed from FIREFLY_RABBITMQ_DSN */
function w4RabbitConnection(): RabbitMqConnectionFactory
{
    return new RabbitMqConnectionFactory(new Config(new Repository(['firefly' => ['eda' => ['rabbitmq' => IntegrationDsn::rabbitmq()]]])));
}

function w4RabbitMessaging(string $exchange, int $maxAttempts = 1): RabbitMqMessageBroker
{
    return new RabbitMqMessageBroker(
        new PhpAmqpLibMessagingChannel(w4RabbitConnection()),
        new RetryPolicy($maxAttempts, new LinearBackOff(0.0)),
        new ListenerInvoker(static function (float $seconds): void {}),
        $exchange,
    );
}

/**
 * Polls until $done answers true or 15 seconds pass. $done must read the test's state BY REFERENCE (a `use (&$x)`
 * closure): an arrow function captures by value and would never see the listener's writes.
 */
function w4RabbitPollUntil(RabbitMqMessageBroker $broker, Closure $done): void
{
    $deadline = microtime(true) + 15;
    while (! $done() && microtime(true) < $deadline) {
        $broker->poll(500);
    }
}

/**
 * Removes what one test declared on the shared broker — every queue is durable, so without this each run would
 * leave its randomly named queues and exchanges behind.
 *
 * @param  list<string>  $topics
 */
function w4RabbitTearDown(string $exchange, array $topics): void
{
    $connection = w4RabbitConnection()->connect();
    $channel = $connection->channel();
    foreach ($topics as $topic) {
        $queue = RabbitMqMessageBroker::queueNameOf($topic, null);
        $channel->queue_delete($queue);
        $channel->queue_delete($queue.'.dlq');
    }
    $channel->exchange_delete($exchange);
    $channel->exchange_delete($exchange.'.dlx');
    $channel->close();
    $connection->close();
}

it('round-trips publish -> poll -> listener -> ack on a real RabbitMQ, with key and headers', function () {
    $suffix = bin2hex(random_bytes(4));
    $topic = 'orders.'.$suffix;
    $exchange = 'firefly.messages.it.'.$suffix;
    $broker = w4RabbitMessaging($exchange);
    $seen = [];
    $broker->subscribeListener($topic, function (Message $message) use (&$seen): void {
        $seen[] = [$message->value, $message->key, $message->headers['x-trace'] ?? null];
    }, null, new ListenerOptions);

    $broker->poll(100); // declares the exchange, the queue and its binding before anything is published
    $broker->publish($topic, 'hello', 'k-1', ['x-trace' => 't-1']);
    w4RabbitPollUntil($broker, static function () use (&$seen): bool {
        return $seen !== [];
    });
    $broker->stop();
    w4RabbitTearDown($exchange, [$topic]);

    expect($seen)->toBe([['hello', 'k-1', 't-1']]);
})->skip(IntegrationDsn::missing(IntegrationDsn::RABBITMQ), 'Set FIREFLY_RABBITMQ_DSN to run the RabbitMQ messaging round trip.')->group('integration');

it('retries, then rejects the message into <queue>.dlq through the dead-letter exchange', function () {
    $suffix = bin2hex(random_bytes(4));
    $topic = 'orders.'.$suffix;
    $exchange = 'firefly.messages.it.'.$suffix;
    $broker = w4RabbitMessaging($exchange, maxAttempts: 2);
    $calls = 0;
    $broker->subscribeListener($topic, function () use (&$calls): void {
        $calls++;

        throw new RuntimeException('listener down');
    }, null, new ListenerOptions);

    $broker->poll(100);
    $broker->publish($topic, 'payload');
    w4RabbitPollUntil($broker, static function () use (&$calls): bool {
        return $calls >= 2;
    });
    $broker->stop();

    $probe = new PhpAmqpLibMessagingChannel(w4RabbitConnection());
    $dead = [];
    $probe->consume(RabbitMqMessageBroker::queueNameOf($topic, null).'.dlq', function (AmqpDelivery $delivery) use (&$dead, $probe): void {
        $dead[] = $delivery->body;
        $probe->ack($delivery->deliveryTag);
    });
    $deadline = microtime(true) + 10;
    while ($dead === [] && microtime(true) < $deadline) {
        $probe->wait(1.0);
    }
    $probe->close();
    w4RabbitTearDown($exchange, [$topic]);

    expect($calls)->toBe(2)->and($dead)->toBe(['payload']);
})->skip(IntegrationDsn::missing(IntegrationDsn::RABBITMQ), 'Set FIREFLY_RABBITMQ_DSN to run the RabbitMQ messaging round trip.')->group('integration');

it("republishes to the listener's own dead-letter topic with the diagnostic headers", function () {
    $suffix = bin2hex(random_bytes(4));
    $topic = 'orders.'.$suffix;
    $parked = 'parked.'.$suffix;
    $exchange = 'firefly.messages.it.'.$suffix;
    $broker = w4RabbitMessaging($exchange);
    $received = [];
    $broker->subscribeListener($topic, static function (): void {
        throw new DomainException('bad order');
    }, null, new ListenerOptions(1, null, $parked));
    $broker->subscribeListener($parked, function (Message $message) use (&$received): void {
        $received[] = $message->headers;
    }, null, new ListenerOptions);

    $broker->poll(100);
    $broker->publish($topic, 'payload');
    w4RabbitPollUntil($broker, static function () use (&$received): bool {
        return $received !== [];
    });
    $broker->stop();
    w4RabbitTearDown($exchange, [$topic, $parked]);

    expect($received[0] ?? [])->toMatchArray([
        'x-original-topic' => $topic,
        'x-exception' => 'DomainException',
        'x-exception-message' => 'bad order',
        'x-delivery-attempts' => '1',
    ]);
})->skip(IntegrationDsn::missing(IntegrationDsn::RABBITMQ), 'Set FIREFLY_RABBITMQ_DSN to run the RabbitMQ messaging round trip.')->group('integration');
