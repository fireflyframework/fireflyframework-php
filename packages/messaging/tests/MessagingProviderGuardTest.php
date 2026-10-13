<?php

declare(strict_types=1);

use Firefly\Config\Config;
use Firefly\Kernel\Exception\Framework\ConfigurationException;
use Firefly\Messaging\Broker\InMemoryMessageBroker;
use Firefly\Messaging\MessagingAutoConfiguration;
use Illuminate\Config\Repository;
use Illuminate\Container\Container;

it('refuses kafka or rabbitmq reaching the core configuration, which means their adapter did not bind', function (string $provider) {
    $config = new Config(new Repository(['firefly' => ['messaging' => ['provider' => $provider]]]));

    expect(fn () => (new MessagingAutoConfiguration)->messageBroker($config, new Container))
        ->toThrow(ConfigurationException::class, "firefly.messaging.provider={$provider}");
})->with(['kafka', 'rabbitmq']);

it('keeps any other unknown provider on the in-memory broker, as before', function () {
    $config = new Config(new Repository(['firefly' => ['messaging' => ['provider' => 'carrier-pigeon']]]));

    expect((new MessagingAutoConfiguration)->messageBroker($config, new Container))->toBeInstanceOf(InMemoryMessageBroker::class);
});
