<?php

declare(strict_types=1);

namespace Firefly\Eda\Database\Outbox;

use Firefly\Config\Config;
use Firefly\Eda\EventPublisher;
use Firefly\Eda\Tracing\BrokerTracing;
use Firefly\Kernel\Exception\Framework\ConfigurationException;
use Illuminate\Contracts\Container\BindingResolutionException;
use Illuminate\Contracts\Container\Container;

/**
 * Turns firefly.eda.database.relay.downstream-provider (or its legacy alias
 * firefly.eda.postgres.relay.downstream_provider) into an ACTUAL downstream EventPublisher for firefly:outbox:relay.
 *
 * RESOLUTION ORDER, so an app can always win:
 *   1. an explicit container binding under self::BINDING — the escape hatch for a downstream that needs credentials,
 *      TLS options or anything else this package has no business knowing about;
 *   2. the configured value read as a container id — an app that has BOUND it has already constructed it;
 *   3. a SHIPPED adapter, named by its ALIAS ('rabbitmq' | 'kafka') or by that adapter's own class-string — two
 *      spellings of one downstream, both built with the adapter's OWN config keys (self::parameters());
 *   4. any other existing class-string, autowired.
 *
 * The adapter classes are referenced as STRINGS behind class_exists(): deptrac forbids an edge to the sibling broker
 * packages, and an outbox-only install must not drag php-amqplib or ext-rdkafka in. The adapter's own config keys —
 * and the gated EdaTracing — are passed as named constructor overrides because each broker's own bean is gated behind
 * firefly.eda.provider=<broker>, which is false here by construction; autowiring would otherwise use constructor
 * defaults and put traceparents on a third party's wire with the compliance gate off. Validation is at RUN time (the
 * relay command), not boot: a downstream bound in another provider's boot() may not exist yet when ours runs.
 *
 * Whatever it resolves to, an OutboxWriter is refused — the relay would re-INSERT every claimed row as PENDING into
 * the outbox it is draining. Not `final`: the deprecated Firefly\Eda\Postgres\Outbox\RelayDownstream extends it.
 */
class RelayDownstream
{
    /** The config key that selects the relay's downstream. */
    public const string PROVIDER_KEY = 'firefly.eda.database.relay.downstream-provider';

    /** The firefly/eda-postgres spelling of the same key, still honoured when the new one is unset. */
    public const string LEGACY_PROVIDER_KEY = 'firefly.eda.postgres.relay.downstream_provider';

    /** The container id an app may bind to supply a fully constructed downstream publisher itself. */
    public const string BINDING = 'firefly.eda.relay.downstream';

    /** @var array<string, array{class: string, package: string}> */
    private const ADAPTERS = [
        'rabbitmq' => ['class' => 'Firefly\\Eda\\Rabbitmq\\RabbitMqEventPublisher', 'package' => 'firefly/eda-rabbitmq'],
        'kafka' => ['class' => 'Firefly\\Eda\\Kafka\\KafkaEventPublisher', 'package' => 'firefly/eda-kafka'],
    ];

    /** The configured downstream name (new key first, then the legacy one), or null when neither is set. */
    public static function configuredProvider(Config $config): ?string
    {
        $provider = match (true) {
            $config->has('firefly.eda.database.relay.downstream-provider') => $config->string('firefly.eda.database.relay.downstream-provider', ''),
            $config->has('firefly.eda.postgres.relay.downstream_provider') => $config->string('firefly.eda.postgres.relay.downstream_provider', ''),
            default => '',
        };

        return trim($provider) === '' ? null : trim($provider);
    }

    /** Resolve the downstream publisher, or throw a ConfigurationException that names the exact thing to change. */
    public static function resolve(Container $container, Config $config): EventPublisher
    {
        $provider = self::configuredProvider($config);

        if ($container->bound(self::BINDING)) {
            return self::guard($provider ?? self::BINDING, self::make($container, self::BINDING, [], $provider ?? self::BINDING));
        }

        if ($provider === null) {
            throw new ConfigurationException(sprintf(
                'Neither %s nor its legacy alias %s is set, so firefly:outbox:relay has no downstream broker to forward to. '
                .'Set it to one of [%s], to the class-string of an EventPublisher, or bind your own under the container id '
                .'"%s". If you did not mean to front a second broker at all, do not run the relay: the outbox already '
                .'delivers committed rows in-process via `php artisan firefly:eda:consume`.',
                self::PROVIDER_KEY,
                self::LEGACY_PROVIDER_KEY,
                implode('|', array_keys(self::ADAPTERS)),
                self::BINDING,
            ));
        }

        return self::guard($provider, self::instantiate($container, $config, $provider));
    }

    private static function instantiate(Container $container, Config $config, string $provider): mixed
    {
        if ($container->bound($provider)) {
            return self::make($container, $provider, [], $provider);
        }

        $alias = isset(self::ADAPTERS[$provider]) ? $provider : self::shippedAdapterAlias($provider);

        if ($alias !== null) {
            $adapter = self::ADAPTERS[$alias];

            if (! class_exists($adapter['class'])) {
                throw new ConfigurationException(sprintf(
                    '%s="%s" but %s is not installed — %s could not be found. Run `composer require %s`, or bind your own '
                    .'downstream EventPublisher under the container id "%s".',
                    self::PROVIDER_KEY,
                    $provider,
                    $adapter['package'],
                    $adapter['class'],
                    $adapter['package'],
                    self::BINDING,
                ));
            }

            return self::make($container, $adapter['class'], self::parameters($container, $config, $alias), $provider);
        }

        if (class_exists($provider)) {
            return self::make($container, $provider, [], $provider);
        }

        throw new ConfigurationException(sprintf(
            '%s="%s" names neither a shipped adapter [%s], nor a bound container id, nor an existing class. Fix the '
            .'value, or bind your own downstream EventPublisher under the container id "%s".',
            self::PROVIDER_KEY,
            $provider,
            implode('|', array_keys(self::ADAPTERS)),
            self::BINDING,
        ));
    }

    private static function shippedAdapterAlias(string $provider): ?string
    {
        foreach (self::ADAPTERS as $alias => $adapter) {
            if ($adapter['class'] === $provider) {
                return $alias;
            }
        }

        return null;
    }

    /**
     * The adapter-specific constructor overrides that carry the sibling package's OWN configuration — and the gated
     * EdaTracing — across the provider gate it is standing behind.
     *
     * @return array<string, mixed>
     */
    private static function parameters(Container $container, Config $config, string $provider): array
    {
        if ($provider === 'rabbitmq') {
            return [
                'exchange' => $config->string('firefly.eda.rabbitmq.exchange', 'firefly.events'),
                'tracing' => BrokerTracing::forContainer($container, $config),
            ];
        }

        if ($provider === 'kafka') {
            return [
                'factory' => self::make(
                    $container,
                    'Firefly\\Eda\\Kafka\\KafkaProducerFactory',
                    ['brokers' => $config->string('firefly.eda.kafka.brokers', '127.0.0.1:9092')],
                    $provider,
                ),
                'tracing' => BrokerTracing::forContainer($container, $config),
            ];
        }

        return [];
    }

    /**
     * @param  array<string, mixed>  $parameters
     */
    private static function make(Container $container, string $id, array $parameters, string $provider): mixed
    {
        try {
            return $container->make($id, $parameters);
        } catch (BindingResolutionException $e) {
            throw new ConfigurationException(sprintf(
                '%s="%s" resolved to "%s", which could not be constructed: %s. Bind a ready-made downstream '
                .'EventPublisher under the container id "%s" instead.',
                self::PROVIDER_KEY,
                $provider,
                $id,
                $e->getMessage(),
                self::BINDING,
            ), previous: $e);
        }
    }

    private static function guard(string $provider, mixed $downstream): EventPublisher
    {
        if ($downstream instanceof OutboxWriter) {
            throw new ConfigurationException(sprintf(
                '%s="%s" resolved to the outbox writer itself (%s). The relay would re-INSERT every claimed row into '
                .'firefly_eda_outbox as PENDING and claim it again forever. Point it at a DISTINCT broker, or bind one '
                .'under the container id "%s".',
                self::PROVIDER_KEY,
                $provider,
                $downstream::class,
                self::BINDING,
            ));
        }

        if (! $downstream instanceof EventPublisher) {
            throw new ConfigurationException(sprintf(
                '%s="%s" resolved to %s, which does not implement %s.',
                self::PROVIDER_KEY,
                $provider,
                get_debug_type($downstream),
                EventPublisher::class,
            ));
        }

        return $downstream;
    }
}
