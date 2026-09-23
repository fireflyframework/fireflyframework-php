<?php

declare(strict_types=1);

namespace Firefly\Eda\Tracing;

use Firefly\Config\Config;
use Illuminate\Contracts\Container\Container;

/**
 * THE ONE PLACE `firefly.eda.tracing.brokers.enabled` IS READ.
 *
 * The three broker adapters (rabbitmq, kafka, postgres) publish through EdaTracing, so the envelope they hand to
 * the transport carries the producer span's `traceparent`. That is exactly what an operator in a regulated
 * deployment may need to refuse: keep the in-process CQRS/EDA spans, but put no trace identifier on a wire a
 * third party reads. The key exists so answering that question does not mean turning tracing off altogether.
 *
 * WHY IT IS A CLASS AND NOT THREE PRIVATE METHODS. It started life as a private `brokerTracing()` copied into each
 * adapter's #[Configuration], which gated the bean and nothing else — and a bean is not the only way a publisher
 * gets built. RelayDownstream resolves the downstream broker with `$container->make($class, $overrides)`, and
 * Illuminate's Container returns a constructor parameter's DEFAULT only when the class is UNBOUND: EdaTracing is
 * always bound (EdaAutoConfiguration::edaTracing(), or firefly/observability's TracerEdaTracing), so autowiring
 * injected the real tracing into `?EdaTracing $tracing = null` no matter what the key said. `firefly:outbox:relay`
 * therefore wrote traceparents onto the downstream broker with the key set to false — the single deployment the
 * key was written for. A gate that lives in the beans fails OPEN everywhere else; a gate that lives here is the
 * same decision for every construction path, and resolve() is called by the three bean methods AND by
 * RelayDownstream's constructor overrides.
 *
 * The key is orthogonal to `firefly.observability.tracing.*`: with those off the bound EdaTracing is already the
 * NoOp and this changes nothing. It only ever DOWNGRADES — it can never switch tracing on.
 */
final class BrokerTracing
{
    /** The config key that decides whether a broker publisher may stamp a traceparent on the wire. */
    public const string ENABLED_KEY = 'firefly.eda.tracing.brokers.enabled';

    /**
     * The EdaTracing a broker publisher gets: the bound one (the real TracerEdaTracing when observability is
     * installed and tracing is on, the NoOp otherwise) unless the key says no.
     *
     * $tracing is nullable because a container with no EdaTracing bound at all — this adapter package booted
     * without firefly/eda's own auto-configuration, as the gating tests do — must still produce a publisher
     * rather than fail on a dependency every publisher treats as optional.
     */
    public static function resolve(Config $config, ?EdaTracing $tracing): EdaTracing
    {
        return $config->bool(self::ENABLED_KEY, true) && $tracing !== null ? $tracing : new NoOpEdaTracing;
    }

    /**
     * The same decision for a caller that has a container rather than an injected dependency — the relay, which
     * builds its downstream publisher by hand because the adapter's own bean is gated off behind a provider it is
     * not running under.
     */
    public static function forContainer(Container $container, Config $config): EdaTracing
    {
        return self::resolve($config, self::bound($container));
    }

    /**
     * The bound EdaTracing, UNGATED — the in-process seam, for a caller that wraps a delivery rather than a wire
     * write (OutboxRelay's traceConsume()). `bound()` is checked first because make() on an unbound interface
     * throws, and a container without firefly/eda's auto-configuration is a supported shape here.
     */
    public static function bound(Container $container): ?EdaTracing
    {
        return $container->bound(EdaTracing::class) ? $container->make(EdaTracing::class) : null;
    }
}
