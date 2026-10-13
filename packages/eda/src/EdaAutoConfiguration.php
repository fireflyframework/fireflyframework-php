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
use Firefly\Eda\DeadLetter\DatabaseDeadLetterStore;
use Firefly\Eda\DeadLetter\DeadLetterStore;
use Firefly\Eda\DeadLetter\InMemoryDeadLetterStore;
use Firefly\Eda\Exception\SerializationException;
use Firefly\Eda\Tracing\EdaTracing;
use Firefly\Eda\Tracing\NoOpEdaTracing;
use Firefly\Kernel\Exception\Framework\ConfigurationException;
use Illuminate\Container\Container;
use Illuminate\Contracts\Container\BindingResolutionException;
use Illuminate\Database\ConnectionResolverInterface;

/**
 * Always-on eda transport wiring. Selects the EventPublisher adapter by firefly.eda.provider (`memory`, the
 * default zero-services bus | `queue`, the async illuminate/queue adapter), the Serializer by
 * firefly.eda.serialization_format (`json` only in M9; avro/protobuf are rejected seams), and the DeadLetterStore
 * (`memory` by default, `database` by firefly.eda.dead-letter.store). #[Order(1000)] places it after user definitions; each #[ConditionalOnMissingBean] lets an app bind its
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

    /**
     * firefly.eda.dead-letter.store: `memory` (the default — in-process, lost on exit) or `database` (durable,
     * replayable, on firefly.eda.dead-letter.connection). It takes the container, not Config and the connection
     * resolver, because neither is safe to inject here: Config is autowired, never bound, so Container::call() hands a
     * nullable `?Config $config = null` its null default; and Laravel aliases ConnectionResolverInterface to `db`
     * even in an app that never registered DatabaseServiceProvider, so injecting it would break every boot without a
     * database. The resolver is made only when `database` is chosen. A direct call with no container (the unit tests)
     * yields the default.
     */
    #[Bean]
    #[ConditionalOnMissingBean(DeadLetterStore::class)]
    public function deadLetterStore(?Container $container = null): DeadLetterStore
    {
        if ($container === null) {
            return new InMemoryDeadLetterStore;
        }

        /** @var Config $config */
        $config = $container->make(Config::class);
        $store = $config->string('firefly.eda.dead-letter.store', 'memory');

        if ($store === 'memory') {
            return new InMemoryDeadLetterStore;
        }

        if ($store !== 'database') {
            throw new ConfigurationException("firefly.eda.dead-letter.store='{$store}' is not one of [memory|database].");
        }

        try {
            $connections = $container->make(ConnectionResolverInterface::class);
        } catch (BindingResolutionException $e) {
            throw new ConfigurationException('firefly.eda.dead-letter.store=database needs a database connection resolver; register Laravel\'s DatabaseServiceProvider (every application has it) or bind your own DeadLetterStore.', previous: $e);
        }

        return new DatabaseDeadLetterStore(
            $connections,
            $config->has('firefly.eda.dead-letter.connection') ? $config->string('firefly.eda.dead-letter.connection') : null,
        );
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
