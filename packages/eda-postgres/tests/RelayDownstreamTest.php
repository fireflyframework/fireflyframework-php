<?php

declare(strict_types=1);

use Firefly\Config\Config;
use Firefly\Eda\Bus\SubscriberRegistry;
use Firefly\Eda\EventPublisher;
use Firefly\Eda\Postgres\Outbox\RelayDownstream;
use Firefly\Eda\Postgres\PostgresEventPublisher;
use Firefly\Eda\Postgres\Tests\Fixtures\SpyDownstreamPublisher;
use Firefly\Kernel\Exception\Framework\ConfigurationException;
use Illuminate\Config\Repository;
use Illuminate\Container\Container;
use Illuminate\Contracts\Config\Repository as RepositoryContract;
use Illuminate\Database\SQLiteConnection;

/**
 * THE RELAY-CAN-NEVER-WORK GATE.
 *
 * firefly:outbox:relay used to read firefly.eda.postgres.relay.downstream_provider purely as an on/off flag and then
 * resolve EventPublisher::class from the container. Under firefly.eda.provider=postgres — the only provider under
 * which the outbox table exists at all — that binding IS the outbox writer, which OutboxRelay's constructor refuses
 * outright (relaying rows back into the same table is an infinite loop). So the command had exactly two reachable
 * outcomes: "no downstream configured, doing nothing" (exit 0) or "the resolved publisher is the outbox writer"
 * (exit 1). Configuring the documented key could not produce a working relay under ANY configuration.
 *
 * RelayDownstream is the missing selection step: the configured value now names the downstream, and every way of
 * getting it wrong raises a ConfigurationException that says exactly what to change.
 */
/**
 * A container shaped like the one the relay command really runs in: the shared SubscriberRegistry the postgres
 * provider binds, and the Illuminate config repository every Laravel app binds — which is what lets an adapter
 * package's own collaborators (RabbitMqConnectionFactory -> Firefly\Config\Config -> Repository) autowire.
 *
 * @param  array<string, mixed>  $values
 * @return array{0: Container, 1: Config}
 */
function relayEnv(array $values = []): array
{
    $repository = new Repository($values);

    $container = new Container;
    $container->instance(SubscriberRegistry::class, new SubscriberRegistry);
    $container->instance(RepositoryContract::class, $repository);

    return [$container, new Config($repository)];
}

it('FAILS LOUDLY when downstream_provider is unset instead of selecting nothing', function () {
    [$container, $config] = relayEnv();

    expect(fn () => RelayDownstream::resolve($container, $config))
        ->toThrow(ConfigurationException::class, 'firefly.eda.postgres.relay.downstream_provider');
});

it('resolves the publisher an app bound explicitly under the relay downstream binding', function () {
    [$container, $config] = relayEnv(['firefly.eda.postgres.relay.downstream_provider' => 'rabbitmq']);
    $spy = new SpyDownstreamPublisher;
    $container->instance(RelayDownstream::BINDING, $spy);

    expect(RelayDownstream::resolve($container, $config))->toBe($spy);
});

it('honours the explicit binding as the escape hatch even with downstream_provider unset', function () {
    // The README and this class's own unset-key error message both offer "bind your own under the container id
    // firefly.eda.relay.downstream" as an ALTERNATIVE to setting downstream_provider. Route 1 therefore has to be
    // reachable with the key absent, or the documented escape hatch is a dead end that exits 1.
    [$container, $config] = relayEnv();
    $spy = new SpyDownstreamPublisher;
    $container->instance(RelayDownstream::BINDING, $spy);

    expect(RelayDownstream::resolve($container, $config))->toBe($spy);
});

it('resolves a downstream named directly by its class-string', function () {
    [$container, $config] = relayEnv(['firefly.eda.postgres.relay.downstream_provider' => SpyDownstreamPublisher::class]);

    expect(RelayDownstream::resolve($container, $config))->toBeInstanceOf(SpyDownstreamPublisher::class);
});

it('builds the shipped rabbitmq adapter from its own config keys when the package is installed', function () {
    if (! class_exists('Firefly\\Eda\\Rabbitmq\\RabbitMqEventPublisher')) {
        $this->markTestSkipped('firefly/eda-rabbitmq is not installed in this environment.');
    }

    [$container, $config] = relayEnv([
        'firefly.eda.postgres.relay.downstream_provider' => 'rabbitmq',
        'firefly.eda.rabbitmq.exchange' => 'relay.exchange',
    ]);

    $downstream = RelayDownstream::resolve($container, $config);

    // A REAL downstream publisher — the whole point of the defect — built with the exchange the app configured
    // rather than the adapter's compiled-in default. The exchange is read reflectively because it is a private
    // readonly constructor property with no accessor; asserting it is the only way to prove the adapter's OWN
    // config key survived the #[ConditionalOnProperty] gate it is standing behind (firefly.eda.provider is
    // `postgres` here, so eda-rabbitmq's own bean never fires and cannot have applied it).
    expect($downstream)->toBeInstanceOf('Firefly\\Eda\\Rabbitmq\\RabbitMqEventPublisher')
        ->and($downstream)->not->toBeInstanceOf(PostgresEventPublisher::class)
        ->and((new ReflectionProperty($downstream, 'exchange'))->getValue($downstream))->toBe('relay.exchange');
});

it('names the missing composer package when the provider alias is not installed', function () {
    [$container, $config] = relayEnv(['firefly.eda.postgres.relay.downstream_provider' => 'nats']);

    expect(fn () => RelayDownstream::resolve($container, $config))
        ->toThrow(ConfigurationException::class, 'nats');
});

it('REFUSES the outbox writer as its own downstream (no re-insert loop)', function () {
    [$container, $config] = relayEnv(['firefly.eda.postgres.relay.downstream_provider' => 'rabbitmq']);
    $container->instance(RelayDownstream::BINDING, new PostgresEventPublisher(
        new SQLiteConnection(new PDO('sqlite::memory:'), ':memory:'),
        new SubscriberRegistry,
    ));

    expect(fn () => RelayDownstream::resolve($container, $config))
        ->toThrow(ConfigurationException::class, 'outbox writer');
});

it('REFUSES a bound object that is not an EventPublisher at all', function () {
    [$container, $config] = relayEnv(['firefly.eda.postgres.relay.downstream_provider' => 'rabbitmq']);
    $container->instance(RelayDownstream::BINDING, new stdClass);

    expect(fn () => RelayDownstream::resolve($container, $config))
        ->toThrow(ConfigurationException::class, EventPublisher::class);
});
