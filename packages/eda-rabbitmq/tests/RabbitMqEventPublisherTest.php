<?php

declare(strict_types=1);

use Firefly\Eda\Bus\SubscriberRegistry;
use Firefly\Eda\JsonSerializer;
use Firefly\Eda\Rabbitmq\RabbitMqEventPublisher;
use Firefly\Eda\Rabbitmq\Tests\Fixtures\CapturingPublishingChannel;
use PhpAmqpLib\Message\AMQPMessage;

it('publishes a durable JSON AMQPMessage to exchange/routingKey', function () {
    $channel = new CapturingPublishingChannel;

    $publisher = new RabbitMqEventPublisher(
        connectionFactory: null,
        registry: new SubscriberRegistry,
        serializer: new JsonSerializer,
        exchange: 'firefly.events',
        channelOverride: $channel,
    );

    $publisher->publish('firefly.events/user.created', 'user.created', ['id' => 7], ['x-a' => 'b']);

    [$msg, $exchange, $routingKey] = $channel->published[0];
    expect($exchange)->toBe('firefly.events')
        ->and($routingKey)->toBe('user.created')
        ->and($msg->get('content_type'))->toBe('application/json')
        ->and($msg->get('delivery_mode'))->toBe(AMQPMessage::DELIVERY_MODE_PERSISTENT);

    $decoded = $channel->decoded();

    expect($decoded['eventType'])->toBe('user.created')
        ->and($decoded['payload'])->toBe(['id' => 7])
        ->and($decoded['headers'])->toBe(['x-a' => 'b']);
});
