<?php

declare(strict_types=1);

use Firefly\Config\Config;
use Firefly\Context\Condition\Attributes\ConditionalOnProperty;
use Firefly\Eda\Kafka\EdaKafkaServiceProvider;
use Firefly\Eda\Kafka\Messaging\KafkaMessageBroker;
use Firefly\Eda\Kafka\Messaging\KafkaMessagingAutoConfiguration;
use Firefly\Messaging\Listener\MessageListenerManifest;
use Firefly\Messaging\MessageBrokerPort;
use Firefly\Messaging\MessagingServiceProvider;
use Firefly\Messaging\MessagingWiringProvider;
use Illuminate\Config\Repository;
use Illuminate\Container\Container;

it('is gated on firefly.messaging.provider=kafka', function () {
    $condition = (new ReflectionMethod(KafkaMessagingAutoConfiguration::class, 'messageBroker'))->getAttributes(ConditionalOnProperty::class)[0]->newInstance();

    expect($condition->name)->toBe('firefly.messaging.provider')->and($condition->havingValue)->toBe('kafka');
});

it('builds the Kafka broker without opening a connection', function () {
    $config = new Config(new Repository(['firefly' => ['messaging' => ['provider' => 'kafka', 'kafka' => ['brokers' => '127.0.0.1:1']]]]));

    expect((new KafkaMessagingAutoConfiguration)->messageBroker($config, new Container))->toBeInstanceOf(KafkaMessageBroker::class);
})->skip(! extension_loaded('rdkafka'), 'needs ext-rdkafka');

it('wins over the core in-memory broker through the real boot', function () {
    $app = fireflyApplication(
        config: ['firefly' => ['messaging' => ['provider' => 'kafka', 'kafka' => ['brokers' => '127.0.0.1:1']]]],
        providers: [MessagingServiceProvider::class, MessagingWiringProvider::class, EdaKafkaServiceProvider::class],
        bindings: [MessageListenerManifest::class => new MessageListenerManifest([])],
    );

    expect($app->make(MessageBrokerPort::class))->toBeInstanceOf(KafkaMessageBroker::class);
})->skip(! extension_loaded('rdkafka'), 'needs ext-rdkafka');
