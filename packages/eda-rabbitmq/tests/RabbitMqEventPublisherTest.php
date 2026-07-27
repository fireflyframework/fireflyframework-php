<?php

declare(strict_types=1);

use Firefly\Eda\Bus\SubscriberRegistry;
use Firefly\Eda\JsonSerializer;
use Firefly\Eda\Rabbitmq\PublishingChannel;
use Firefly\Eda\Rabbitmq\RabbitMqEventPublisher;
use PhpAmqpLib\Message\AMQPMessage;

it('publishes a durable JSON AMQPMessage to exchange/routingKey', function () {
    /** @var list<array{0: AMQPMessage, 1: string, 2: string}> $captured */
    $captured = [];
    $channel = new class($captured) implements PublishingChannel
    {
        /**
         * @param  list<array{0: AMQPMessage, 1: string, 2: string}>  $captured
         */
        public function __construct(public array &$captured) {}

        public function exchange_declare(string $exchange, string $type, bool $passive, bool $durable, bool $autoDelete): void {}

        public function basic_publish(AMQPMessage $msg, string $exchange, string $routingKey): void
        {
            $this->captured[] = [$msg, $exchange, $routingKey];
        }
    };

    $publisher = new RabbitMqEventPublisher(
        connectionFactory: null,
        registry: new SubscriberRegistry,
        serializer: new JsonSerializer,
        exchange: 'firefly.events',
        channelOverride: $channel,
    );

    $publisher->publish('firefly.events/user.created', 'user.created', ['id' => 7], ['x-a' => 'b']);

    [$msg, $exchange, $routingKey] = $captured[0];
    expect($exchange)->toBe('firefly.events')
        ->and($routingKey)->toBe('user.created')
        ->and($msg->get('content_type'))->toBe('application/json')
        ->and($msg->get('delivery_mode'))->toBe(AMQPMessage::DELIVERY_MODE_PERSISTENT);

    $decoded = json_decode($msg->getBody(), true);
    if (! is_array($decoded)) {
        throw new RuntimeException('Expected json_decode to return an array.');
    }

    expect($decoded['eventType'])->toBe('user.created')
        ->and($decoded['payload'])->toBe(['id' => 7])
        ->and($decoded['headers'])->toBe(['x-a' => 'b']);
});
