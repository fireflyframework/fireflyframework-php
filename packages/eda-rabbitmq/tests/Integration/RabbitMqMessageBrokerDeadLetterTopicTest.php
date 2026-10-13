<?php

declare(strict_types=1);

use Firefly\Config\Config;
use Firefly\Eda\Rabbitmq\Messaging\PhpAmqpLibMessagingChannel;
use Firefly\Eda\Rabbitmq\Messaging\RabbitMqMessageBroker;
use Firefly\Eda\Rabbitmq\RabbitMqConnectionFactory;
use Firefly\Eda\Rabbitmq\Tests\Support\RabbitMqIntegrationTestCase;
use Firefly\Messaging\Container\LinearBackOff;
use Firefly\Messaging\Container\ListenerInvoker;
use Firefly\Messaging\Container\ListenerOptions;
use Firefly\Messaging\Container\RetryPolicy;
use Firefly\Testing\Integration\IntegrationDsn;
use Illuminate\Config\Repository;
use PhpAmqpLib\Message\AMQPMessage;

uses(RabbitMqIntegrationTestCase::class);

/** @return RabbitMqConnectionFactory over firefly.eda.rabbitmq.* parsed from FIREFLY_RABBITMQ_DSN */
function w4RabbitDeadLetterFactory(): RabbitMqConnectionFactory
{
    return new RabbitMqConnectionFactory(new Config(new Repository(['firefly' => ['eda' => ['rabbitmq' => IntegrationDsn::rabbitmq()]]])));
}

it("parks an exhausted delivery on the listener's dead-letter topic even when nobody consumes that topic", function () {
    $factory = w4RabbitDeadLetterFactory();
    $suffix = bin2hex(random_bytes(4));
    $exchange = 'firefly.messages.test.'.$suffix;
    $topic = 'orders.'.$suffix;
    $parked = 'orders.parked.'.$suffix;
    $parkingQueue = RabbitMqMessageBroker::queueNameOf($parked, null);

    $broker = new RabbitMqMessageBroker(
        new PhpAmqpLibMessagingChannel($factory),
        new RetryPolicy(1, new LinearBackOff(0.0)),
        new ListenerInvoker(static function (float $s): void {}),
        exchange: $exchange,
    );
    $broker->subscribeListener($topic, static function (): void {
        throw new DomainException('bad order');
    }, null, new ListenerOptions(1, null, $parked));

    // The first poll declares the topology — the subscription's queue AND the dead-letter topic's parking queue.
    $broker->poll(100);
    $broker->publish($topic, 'payload', 'k-1');

    $deadline = time() + 15;
    $handled = false;
    while (! $handled && time() < $deadline) {
        $handled = $broker->poll(1000);
    }
    $broker->stop();

    $connection = $factory->connect();
    $channel = $connection->channel();
    $message = null;
    $deadline = time() + 10;
    while ($message === null && time() < $deadline) {
        $message = $channel->basic_get($parkingQueue, true);
        if ($message === null) {
            usleep(200_000);
        }
    }

    foreach ([$parkingQueue, $parkingQueue.'.dlq', 'firefly.'.$topic, 'firefly.'.$topic.'.dlq'] as $queue) {
        $channel->queue_delete($queue);
    }
    $channel->exchange_delete($exchange);
    $channel->exchange_delete($exchange.'.dlx');
    $channel->close();
    $connection->close();

    expect($handled)->toBeTrue()
        ->and($message)->toBeInstanceOf(AMQPMessage::class)
        ->and($message?->getBody())->toBe('payload');
})->skip(IntegrationDsn::missing(IntegrationDsn::RABBITMQ), 'Set FIREFLY_RABBITMQ_DSN to run the @group integration RabbitMQ dead-letter topic test.')->group('integration');
