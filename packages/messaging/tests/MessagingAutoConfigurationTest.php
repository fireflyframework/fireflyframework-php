<?php

declare(strict_types=1);

use Firefly\Config\Config;
use Firefly\Container\Attributes\Configuration;
use Firefly\Container\Attributes\Order;
use Firefly\Context\Condition\Attributes\ConditionalOnMissingBean;
use Firefly\Messaging\Broker\InMemoryMessageBroker;
use Firefly\Messaging\Broker\QueueMessageBroker;
use Firefly\Messaging\DeadLetter\DeadLetterStore;
use Firefly\Messaging\DeadLetter\InMemoryDeadLetterStore;
use Firefly\Messaging\MessageBrokerPort;
use Firefly\Messaging\MessagingAutoConfiguration;
use Illuminate\Config\Repository;
use Illuminate\Container\Container;

/**
 * @param  array<string, mixed>  $messaging
 */
function messagingConfig(array $messaging): Config
{
    return new Config(new Repository(['firefly' => ['messaging' => $messaging]]));
}

it('is a #[Configuration] ordered 1000 whose beans are gated #[ConditionalOnMissingBean]', function () {
    $class = new ReflectionClass(MessagingAutoConfiguration::class);

    expect($class->getAttributes(Configuration::class))->not->toBe([])
        ->and($class->getAttributes(Order::class)[0]->newInstance()->order)->toBe(1000)
        ->and($class->getMethod('messageBroker')->getAttributes(ConditionalOnMissingBean::class)[0]->newInstance()->type)->toBe(MessageBrokerPort::class)
        ->and($class->getMethod('deadLetterStore')->getAttributes(ConditionalOnMissingBean::class)[0]->newInstance()->type)->toBe(DeadLetterStore::class);
});

it('selects InMemoryMessageBroker by default and QueueMessageBroker when firefly.messaging.provider = queue', function () {
    $auto = new MessagingAutoConfiguration;
    $container = new Container;

    expect($auto->messageBroker(messagingConfig([]), $container))->toBeInstanceOf(InMemoryMessageBroker::class)
        ->and($auto->messageBroker(messagingConfig(['provider' => 'queue']), $container))->toBeInstanceOf(QueueMessageBroker::class)
        ->and($auto->deadLetterStore())->toBeInstanceOf(InMemoryDeadLetterStore::class);
});
