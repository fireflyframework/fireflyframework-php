<?php

declare(strict_types=1);

use Firefly\Config\Config;
use Firefly\Context\Condition\Attributes\ConditionalOnProperty;
use Firefly\Eda\Rabbitmq\EdaRabbitmqServiceProvider;
use Firefly\Eda\Rabbitmq\Messaging\RabbitMqMessageBroker;
use Firefly\Eda\Rabbitmq\Messaging\RabbitMqMessagingAutoConfiguration;
use Firefly\Messaging\Listener\MessageListenerManifest;
use Firefly\Messaging\MessageBrokerPort;
use Firefly\Messaging\MessagingServiceProvider;
use Firefly\Messaging\MessagingWiringProvider;
use Illuminate\Config\Repository;
use Illuminate\Container\Container;

it('is gated on firefly.messaging.provider=rabbitmq', function () {
    $condition = (new ReflectionMethod(RabbitMqMessagingAutoConfiguration::class, 'messageBroker'))->getAttributes(ConditionalOnProperty::class)[0]->newInstance();

    expect($condition->name)->toBe('firefly.messaging.provider')->and($condition->havingValue)->toBe('rabbitmq');
});

it('builds the RabbitMQ broker without opening a connection', function () {
    $config = new Config(new Repository(['firefly' => ['messaging' => ['provider' => 'rabbitmq'], 'eda' => ['rabbitmq' => ['host' => '127.0.0.1', 'port' => 1]]]]));

    expect((new RabbitMqMessagingAutoConfiguration)->messageBroker($config, new Container))->toBeInstanceOf(RabbitMqMessageBroker::class);
});

it('wins over the core in-memory broker through the real boot', function () {
    $app = fireflyApplication(
        config: ['firefly' => ['messaging' => ['provider' => 'rabbitmq'], 'eda' => ['rabbitmq' => ['host' => '127.0.0.1', 'port' => 1]]]],
        providers: [MessagingServiceProvider::class, MessagingWiringProvider::class, EdaRabbitmqServiceProvider::class],
        bindings: [MessageListenerManifest::class => new MessageListenerManifest([])],
    );

    expect($app->make(MessageBrokerPort::class))->toBeInstanceOf(RabbitMqMessageBroker::class);
});
