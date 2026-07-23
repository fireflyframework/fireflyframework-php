<?php

declare(strict_types=1);

namespace Firefly\Eda;

use Firefly\Config\Config;
use Firefly\Container\Attributes\Bean;
use Firefly\Container\Attributes\Configuration;
use Firefly\Container\Attributes\Order;
use Firefly\Context\Condition\Attributes\ConditionalOnMissingBean;
use Firefly\Eda\Bus\InMemoryEventBus;
use Firefly\Eda\Bus\QueueEventBus;
use Firefly\Eda\Bus\SubscriberRegistry;
use Firefly\Eda\DeadLetter\DeadLetterStore;
use Firefly\Eda\DeadLetter\InMemoryDeadLetterStore;
use Firefly\Eda\Exception\SerializationException;
use Illuminate\Container\Container;

/**
 * Always-on eda transport wiring. Selects the EventPublisher adapter by firefly.eda.provider (`memory`, the
 * default zero-services bus | `queue`, the async illuminate/queue adapter), the Serializer by
 * firefly.eda.serialization_format (`json` only in M9; avro/protobuf are rejected seams), and the DeadLetterStore
 * default. #[Order(1000)] places it after user definitions; each #[ConditionalOnMissingBean] lets an app bind its
 * own and win. Both adapters are singletons (the #[Bean] default), so the wiring pass and the queue worker share
 * ONE SubscriberRegistry. Mirrors SchedulingAutoConfiguration / DataAutoConfiguration.
 */
#[Configuration]
#[Order(1000)]
final class EdaAutoConfiguration
{
    #[Bean]
    #[ConditionalOnMissingBean(EventPublisher::class)]
    public function eventPublisher(Config $config, Container $container): EventPublisher
    {
        if ($config->string('firefly.eda.provider', 'memory') === 'queue') {
            $connection = $config->has('firefly.eda.queue.connection') ? $config->string('firefly.eda.queue.connection') : null;
            $queue = $config->has('firefly.eda.queue.name') ? $config->string('firefly.eda.queue.name') : null;

            return new QueueEventBus(new SubscriberRegistry, $container, $connection, $queue);
        }

        return new InMemoryEventBus(new SubscriberRegistry);
    }

    #[Bean]
    #[ConditionalOnMissingBean(Serializer::class)]
    public function serializer(Config $config): Serializer
    {
        $format = $config->string('firefly.eda.serialization_format', 'json');

        return match ($format) {
            'json' => new JsonSerializer,
            default => throw new SerializationException(
                "Unsupported serialization format '{$format}'. Only 'json' ships in M9; avro/protobuf are SP-4 seams.",
            ),
        };
    }

    #[Bean]
    #[ConditionalOnMissingBean(DeadLetterStore::class)]
    public function deadLetterStore(): DeadLetterStore
    {
        return new InMemoryDeadLetterStore;
    }
}
