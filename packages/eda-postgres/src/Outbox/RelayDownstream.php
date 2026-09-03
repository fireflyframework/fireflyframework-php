<?php

declare(strict_types=1);

namespace Firefly\Eda\Postgres\Outbox;

use Firefly\Config\Config;
use Firefly\Eda\EventPublisher;
use Firefly\Eda\Postgres\PostgresEventPublisher;
use Firefly\Kernel\Exception\Framework\ConfigurationException;
use Illuminate\Contracts\Container\BindingResolutionException;
use Illuminate\Contracts\Container\Container;

/**
 * Turns firefly.eda.postgres.relay.downstream_provider into an ACTUAL downstream EventPublisher for
 * firefly:outbox:relay — the selection step that was missing entirely.
 *
 * WHAT WAS BROKEN. OutboxRelayCommand read the key purely as an on/off flag and then did
 * `$container->make(EventPublisher::class)`. But firefly:outbox:relay only exists under
 * firefly.eda.provider=postgres, and under that provider PostgresOutboxAutoConfiguration binds EventPublisher to
 * the outbox WRITER. So the command's own guard (and OutboxRelay's constructor guard behind it) rejected the only
 * thing it could ever resolve: relaying rows through the writer would INSERT them straight back into
 * firefly_eda_outbox as PENDING — an infinite loop that grows the table forever. The result was a command with two
 * reachable outcomes and no third: "no downstream configured, no-op, exit 0" or "the resolved publisher is the
 * outbox writer, exit 1". Setting the documented config key could not produce a working relay under any
 * configuration; it merely swapped a silent no-op for a loud one.
 *
 * HOW IT RESOLVES NOW, in order, so an app can always win:
 *   1. an explicit container binding under self::BINDING — the escape hatch for a downstream that needs credentials,
 *      TLS options or anything else this package has no business knowing about;
 *   2. the configured value read as a container id or class-string — `downstream_provider` may name a binding or a
 *      publisher class directly;
 *   3. a shipped adapter ALIAS ('rabbitmq' | 'kafka') mapped to that package's publisher, built with the adapter's
 *      OWN config keys.
 *
 * WHY THE ADAPTER CLASSES ARE REFERENCED AS STRINGS. deptrac's layer graph forbids an EdaPostgres -> EdaRabbitmq /
 * EdaKafka edge (see deptrac.yaml: every broker is a sibling leaf package, depended on by NONE), and rightly so —
 * firefly/eda-postgres must not drag php-amqplib or ext-rdkafka into a Postgres-only install. A class-string plus a
 * class_exists() guard keeps the compile-time graph clean while still producing a real, fully configured publisher
 * when the operator has installed the sibling package. When they have not, self::ADAPTERS names the exact composer
 * package to require.
 *
 * WHY THE ADAPTER'S OWN CONFIG KEYS ARE PASSED EXPLICITLY. Each broker package gates ITS publisher bean behind
 * #[ConditionalOnProperty(firefly.eda.provider=<its own name>)], which is false here by construction (the provider
 * is postgres). Resolving the class through the container therefore autowires it with the constructor DEFAULTS, so
 * an app that had carefully set firefly.eda.rabbitmq.exchange would have silently relayed to `firefly.events`
 * instead — a fresh silent misconfiguration in place of the one being fixed. self::parameters() passes those values
 * as named constructor overrides so the relay honours exactly the settings the adapter itself would have read.
 *
 * WHY VALIDATION IS RUNTIME AND NOT BOOT-TIME. Throwing from EdaPostgresBootServiceProvider::boot() was considered
 * and rejected: a downstream bound in ANOTHER provider's boot() may not exist yet when ours runs, so a boot-time
 * check would fail applications that are correctly configured, and it would fail every web request and every
 * unrelated artisan command over a relay-only concern. The relay command is the one place the value is load-bearing,
 * so that is where it is validated — immediately, before a single row is claimed, and never at the first publish.
 */
final class RelayDownstream
{
    /** The config key that selects the relay's downstream. */
    public const string PROVIDER_KEY = 'firefly.eda.postgres.relay.downstream_provider';

    /** The container id an app may bind to supply a fully constructed downstream publisher itself. */
    public const string BINDING = 'firefly.eda.relay.downstream';

    /**
     * The shipped broker adapters, by the alias an operator writes in `downstream_provider`.
     *
     * @var array<string, array{class: string, package: string}>
     */
    private const ADAPTERS = [
        'rabbitmq' => ['class' => 'Firefly\\Eda\\Rabbitmq\\RabbitMqEventPublisher', 'package' => 'firefly/eda-rabbitmq'],
        'kafka' => ['class' => 'Firefly\\Eda\\Kafka\\KafkaEventPublisher', 'package' => 'firefly/eda-kafka'],
    ];

    /** The configured downstream name, or null when the operator has not opted into the relay at all. */
    public static function configuredProvider(Config $config): ?string
    {
        if (! $config->has(self::PROVIDER_KEY)) {
            return null;
        }

        $provider = trim($config->string(self::PROVIDER_KEY, ''));

        return $provider === '' ? null : $provider;
    }

    /**
     * Resolve the downstream publisher, or throw a ConfigurationException that names the exact thing to change.
     * Never returns the outbox writer.
     */
    public static function resolve(Container $container, Config $config): EventPublisher
    {
        $provider = self::configuredProvider($config);

        // Route 1 is checked BEFORE the unset-key failure, not after: an app that has bound a ready-made downstream
        // has already named it, and demanding a redundant downstream_provider alongside the binding would reject a
        // configuration this class's own error message (and the README) tell operators to use.
        if ($container->bound(self::BINDING)) {
            return self::guard($provider ?? self::BINDING, self::make($container, self::BINDING, [], $provider ?? self::BINDING));
        }

        if ($provider === null) {
            throw new ConfigurationException(sprintf(
                '%s is not set, so firefly:outbox:relay has no downstream broker to forward to. Set it to one of [%s], '
                .'to the class-string of an EventPublisher, or bind your own under the container id "%s". If you did not '
                .'mean to front a second broker at all, do not run the relay: firefly.eda.provider=postgres already '
                .'delivers committed outbox rows in-process via `php artisan firefly:eda:consume`.',
                self::PROVIDER_KEY,
                implode('|', array_keys(self::ADAPTERS)),
                self::BINDING,
            ));
        }

        return self::guard($provider, self::instantiate($container, $config, $provider));
    }

    /**
     * Walk the remaining resolution routes in order (route 1, the explicit binding, is handled by resolve() so it
     * can win even with the key unset). Anything the container cannot build is reported with the
     * BindingResolutionException attached, because "which constructor argument could not be satisfied" is the only
     * detail that makes an autowiring failure actionable.
     */
    private static function instantiate(Container $container, Config $config, string $provider): mixed
    {
        if ($container->bound($provider) || class_exists($provider)) {
            return self::make($container, $provider, [], $provider);
        }

        $adapter = self::ADAPTERS[$provider] ?? null;

        if ($adapter === null) {
            throw new ConfigurationException(sprintf(
                '%s="%s" names neither a shipped adapter [%s], nor a bound container id, nor an existing class. Fix the '
                .'value, or bind your own downstream EventPublisher under the container id "%s".',
                self::PROVIDER_KEY,
                $provider,
                implode('|', array_keys(self::ADAPTERS)),
                self::BINDING,
            ));
        }

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

        return self::make($container, $adapter['class'], self::parameters($container, $config, $provider), $provider);
    }

    /**
     * The adapter-specific constructor overrides that carry the sibling package's OWN configuration across the
     * provider gate it is standing behind. Named by constructor parameter — which PHP 8 named arguments already
     * make part of those classes' public API — so a rename there surfaces here as a loud BindingResolutionException
     * rather than a quietly-defaulted broker address.
     *
     * @return array<string, mixed>
     */
    private static function parameters(Container $container, Config $config, string $provider): array
    {
        if ($provider === 'rabbitmq') {
            return ['exchange' => $config->string('firefly.eda.rabbitmq.exchange', 'firefly.events')];
        }

        if ($provider === 'kafka') {
            // The broker list lives one level down, on KafkaProducerFactory, so it is built here and injected as the
            // publisher's `factory` argument — parameter overrides do not reach nested dependencies.
            return ['factory' => self::make(
                $container,
                'Firefly\\Eda\\Kafka\\KafkaProducerFactory',
                ['brokers' => $config->string('firefly.eda.kafka.brokers', '127.0.0.1:9092')],
                $provider,
            )];
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

    /**
     * The last line of defence, and the reason OutboxRelay's own constructor guard is not enough on its own: a clear
     * message at CONFIGURATION time beats a LogicException from deep inside the relay. Refusing the outbox writer
     * here is what keeps `downstream_provider` from ever pointing the relay back at the table it is draining.
     */
    private static function guard(string $provider, mixed $downstream): EventPublisher
    {
        if ($downstream instanceof PostgresEventPublisher) {
            throw new ConfigurationException(sprintf(
                '%s="%s" resolved to the Postgres outbox writer itself. The relay would re-INSERT every claimed row '
                .'into firefly_eda_outbox as PENDING and claim it again forever. Point it at a DISTINCT broker, or bind '
                .'one under the container id "%s".',
                self::PROVIDER_KEY,
                $provider,
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
