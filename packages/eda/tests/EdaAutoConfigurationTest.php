<?php

declare(strict_types=1);

use Firefly\Config\Config;
use Firefly\Container\Attributes\Configuration;
use Firefly\Container\Attributes\Order;
use Firefly\Context\Condition\Attributes\ConditionalOnMissingBean;
use Firefly\Eda\Bus\InMemoryEventBus;
use Firefly\Eda\Bus\QueueEventBus;
use Firefly\Eda\DeadLetter\DeadLetterStore;
use Firefly\Eda\DeadLetter\InMemoryDeadLetterStore;
use Firefly\Eda\EdaAutoConfiguration;
use Firefly\Eda\EventPublisher;
use Firefly\Eda\Exception\SerializationException;
use Firefly\Eda\JsonSerializer;
use Firefly\Eda\Serializer;
use Illuminate\Config\Repository;
use Illuminate\Container\Container;

/**
 * @param  array<string, mixed>  $eda
 */
function edaConfig(array $eda): Config
{
    return new Config(new Repository(['firefly' => ['eda' => $eda]]));
}

it('is a #[Configuration] ordered 1000 whose beans are gated #[ConditionalOnMissingBean]', function () {
    $class = new ReflectionClass(EdaAutoConfiguration::class);

    expect($class->getAttributes(Configuration::class))->not->toBe([])
        ->and($class->getAttributes(Order::class)[0]->newInstance()->order)->toBe(1000)
        ->and($class->getMethod('eventPublisher')->getAttributes(ConditionalOnMissingBean::class)[0]->newInstance()->type)->toBe(EventPublisher::class)
        ->and($class->getMethod('serializer')->getAttributes(ConditionalOnMissingBean::class)[0]->newInstance()->type)->toBe(Serializer::class)
        ->and($class->getMethod('deadLetterStore')->getAttributes(ConditionalOnMissingBean::class)[0]->newInstance()->type)->toBe(DeadLetterStore::class);
});

it('selects InMemoryEventBus by default and QueueEventBus when firefly.eda.provider = queue', function () {
    $auto = new EdaAutoConfiguration;
    $container = new Container;

    expect($auto->eventPublisher(edaConfig([]), $container))->toBeInstanceOf(InMemoryEventBus::class)
        ->and($auto->eventPublisher(edaConfig(['provider' => 'queue']), $container))->toBeInstanceOf(QueueEventBus::class);
});

it('provides a JSON serializer by default and rejects unsupported formats', function () {
    $auto = new EdaAutoConfiguration;

    expect($auto->serializer(edaConfig([])))->toBeInstanceOf(JsonSerializer::class)
        ->and($auto->deadLetterStore())->toBeInstanceOf(InMemoryDeadLetterStore::class);

    expect(fn () => $auto->serializer(edaConfig(['serialization_format' => 'avro'])))
        ->toThrow(SerializationException::class);
});
