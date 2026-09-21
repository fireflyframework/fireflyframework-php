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
use Firefly\Eda\Tracing\EdaTracing;
use Firefly\Eda\Tracing\NoOpEdaTracing;
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
    public function eventPublisher(Config $config, Container $container, ?EdaTracing $tracing = null): EventPublisher
    {
        if ($config->string('firefly.eda.provider', 'memory') === 'queue') {
            $connection = $config->has('firefly.eda.queue.connection') ? $config->string('firefly.eda.queue.connection') : null;
            $queue = $config->has('firefly.eda.queue.name') ? $config->string('firefly.eda.queue.name') : null;

            return new QueueEventBus(new SubscriberRegistry, $container, $connection, $queue, $tracing);
        }

        return new InMemoryEventBus(new SubscriberRegistry, $tracing);
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

    /**
     * The tracing seam's default. Optional on eventPublisher() (with a null default) so the unit test's direct
     * two-argument call still compiles; in a booted container Laravel's Container::call() injects this bean —
     * or firefly/observability's TracerEdaTracing, which registers first by #[Order] and makes this one back off.
     */
    #[Bean]
    #[ConditionalOnMissingBean(EdaTracing::class)]
    public function edaTracing(): EdaTracing
    {
        return new NoOpEdaTracing;
    }
}
