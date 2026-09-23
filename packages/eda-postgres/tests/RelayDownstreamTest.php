<?php

declare(strict_types=1);

use Firefly\Config\Config;
use Firefly\Eda\Bus\SubscriberRegistry;
use Firefly\Eda\EventPublisher;
use Firefly\Eda\Postgres\Outbox\RelayDownstream;
use Firefly\Eda\Postgres\PostgresEventPublisher;
use Firefly\Eda\Postgres\Tests\Fixtures\SpyDownstreamPublisher;
use Firefly\Eda\Postgres\Tests\Fixtures\StampingEdaTracing;
use Firefly\Eda\Tracing\EdaTracing;
use Firefly\Eda\Tracing\NoOpEdaTracing;
use Firefly\Kernel\Exception\Framework\ConfigurationException;
use Illuminate\Config\Repository;
use Illuminate\Container\Container;
use Illuminate\Contracts\Config\Repository as RepositoryContract;
use Illuminate\Database\SQLiteConnection;
use PHPUnit\Framework\Assert;

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
        Assert::markTestSkipped('firefly/eda-rabbitmq is not installed in this environment.');
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

/**
 * THE GATE THAT FAILED OPEN EXACTLY WHERE IT MATTERED.
 *
 * `firefly.eda.tracing.brokers.enabled` is documented as the way to "keep in-process spans while refusing to put
 * trace identifiers on a wire someone else reads". It started life as a private helper inside the three adapters'
 * #[Bean] methods — and the relay's downstream publisher is not built by a bean. Each adapter gates ITS publisher
 * behind #[ConditionalOnProperty(firefly.eda.provider=<its own name>)], false here by construction, so
 * RelayDownstream builds the class through the container instead; Illuminate returns a constructor parameter's
 * DEFAULT only when the class is UNBOUND, and EdaTracing is bound in every app that has firefly/eda installed.
 * The publisher's `?EdaTracing $tracing = null` was therefore satisfied with the REAL tracing whatever the key
 * said, so `firefly:outbox:relay` wrote traceparents onto the third-party broker with the control switched off —
 * the one deployment the control exists for. The fix is a `tracing` constructor override beside `exchange` /
 * `factory`, computed by the shared BrokerTracing resolver, so every construction path makes one decision.
 *
 * Read reflectively for the same reason `exchange` is above: a private readonly constructor property with no
 * accessor, and the only way to prove which EdaTracing actually reached the publisher.
 */
it('hands the shipped rabbitmq downstream the bound EdaTracing when the broker gate is untouched', function () {
    if (! class_exists('Firefly\\Eda\\Rabbitmq\\RabbitMqEventPublisher')) {
        Assert::markTestSkipped('firefly/eda-rabbitmq is not installed in this environment.');
    }

    [$container, $config] = relayEnv(['firefly.eda.postgres.relay.downstream_provider' => 'rabbitmq']);
    $tracing = new StampingEdaTracing;
    $container->instance(EdaTracing::class, $tracing);

    $downstream = RelayDownstream::resolve($container, $config);

    expect((new ReflectionProperty($downstream, 'tracing'))->getValue($downstream))->toBe($tracing);
});

it('REFUSES to give the rabbitmq downstream any tracing when the broker gate is false', function () {
    if (! class_exists('Firefly\\Eda\\Rabbitmq\\RabbitMqEventPublisher')) {
        Assert::markTestSkipped('firefly/eda-rabbitmq is not installed in this environment.');
    }

    [$container, $config] = relayEnv([
        'firefly.eda.postgres.relay.downstream_provider' => 'rabbitmq',
        'firefly.eda.tracing.brokers.enabled' => false,
    ]);
    $container->instance(EdaTracing::class, new StampingEdaTracing);

    $downstream = RelayDownstream::resolve($container, $config);

    // Autowiring used to win here and hand it the stamping instance regardless of the key.
    expect((new ReflectionProperty($downstream, 'tracing'))->getValue($downstream))->toBeInstanceOf(NoOpEdaTracing::class);
});

it('applies the same gate to the shipped kafka downstream, whose brokers it also carries across', function () {
    if (! class_exists('Firefly\\Eda\\Kafka\\KafkaEventPublisher')) {
        Assert::markTestSkipped('firefly/eda-kafka is not installed in this environment.');
    }

    [$container, $config] = relayEnv([
        'firefly.eda.postgres.relay.downstream_provider' => 'kafka',
        'firefly.eda.tracing.brokers.enabled' => false,
    ]);
    $container->instance(EdaTracing::class, new StampingEdaTracing);

    $downstream = RelayDownstream::resolve($container, $config);

    expect((new ReflectionProperty($downstream, 'tracing'))->getValue($downstream))->toBeInstanceOf(NoOpEdaTracing::class)
        // The `factory` override still reaches the nested dependency — the gate is additive, not a replacement.
        ->and((new ReflectionProperty($downstream, 'factory'))->getValue($downstream))->toBeInstanceOf('Firefly\\Eda\\Kafka\\KafkaProducerFactory');
});

/**
 * THE SAME GATE, THE OTHER SPELLING OF THE SAME DOWNSTREAM.
 *
 * `downstream_provider` accepts a shipped adapter by its alias ('rabbitmq') OR by its own class-string, and the
 * class-string route used to short-circuit on class_exists() BEFORE the adapter table was consulted. The publisher
 * was then built by bare container autowiring, which reinstated the exact failure the overrides above exist to
 * prevent: `firefly.eda.rabbitmq.exchange` silently fell back to `firefly.events`, and — because Illuminate
 * satisfies `?EdaTracing $tracing = null` from the container whenever EdaTracing is bound — the compliance gate
 * FAILED OPEN. Naming the adapter more explicitly was a way around the control.
 */
it('applies the broker gate to the shipped rabbitmq adapter named by its own class-string', function () {
    if (! class_exists('Firefly\\Eda\\Rabbitmq\\RabbitMqEventPublisher')) {
        Assert::markTestSkipped('firefly/eda-rabbitmq is not installed in this environment.');
    }

    [$container, $config] = relayEnv([
        'firefly.eda.postgres.relay.downstream_provider' => 'Firefly\\Eda\\Rabbitmq\\RabbitMqEventPublisher',
        'firefly.eda.rabbitmq.exchange' => 'relay.exchange',
        'firefly.eda.tracing.brokers.enabled' => false,
    ]);
    $container->instance(EdaTracing::class, new StampingEdaTracing);

    $downstream = RelayDownstream::resolve($container, $config);

    expect($downstream)->toBeInstanceOf('Firefly\\Eda\\Rabbitmq\\RabbitMqEventPublisher')
        ->and((new ReflectionProperty($downstream, 'tracing'))->getValue($downstream))->toBeInstanceOf(NoOpEdaTracing::class)
        // The adapter's OWN config key rides the same route, so the two spellings are one downstream.
        ->and((new ReflectionProperty($downstream, 'exchange'))->getValue($downstream))->toBe('relay.exchange');
});

it('applies the broker gate to the shipped kafka adapter named by its own class-string', function () {
    if (! class_exists('Firefly\\Eda\\Kafka\\KafkaEventPublisher')) {
        Assert::markTestSkipped('firefly/eda-kafka is not installed in this environment.');
    }

    [$container, $config] = relayEnv([
        'firefly.eda.postgres.relay.downstream_provider' => 'Firefly\\Eda\\Kafka\\KafkaEventPublisher',
        'firefly.eda.tracing.brokers.enabled' => false,
    ]);
    $container->instance(EdaTracing::class, new StampingEdaTracing);

    $downstream = RelayDownstream::resolve($container, $config);

    expect((new ReflectionProperty($downstream, 'tracing'))->getValue($downstream))->toBeInstanceOf(NoOpEdaTracing::class)
        ->and((new ReflectionProperty($downstream, 'factory'))->getValue($downstream))->toBeInstanceOf('Firefly\\Eda\\Kafka\\KafkaProducerFactory');
});

it('leaves an adapter class-string the app has BOUND itself exactly as the app built it', function () {
    // Route 2 still wins over the adapter table: an app that bound the id constructed the publisher itself, so
    // every argument is already its own choice and constructor overrides would only defeat the binding (Illuminate
    // skips a shared instance the moment make() is handed parameters).
    [$container, $config] = relayEnv([
        'firefly.eda.postgres.relay.downstream_provider' => 'Firefly\\Eda\\Rabbitmq\\RabbitMqEventPublisher',
        'firefly.eda.tracing.brokers.enabled' => false,
    ]);
    $spy = new SpyDownstreamPublisher;
    $container->instance('Firefly\\Eda\\Rabbitmq\\RabbitMqEventPublisher', $spy);

    expect(RelayDownstream::resolve($container, $config))->toBe($spy);
});

it('KNOWN-LATENT: a FOREIGN publisher class is autowired, so its tracing is the application\'s to choose', function () {
    // The documented boundary of the gate, pinned so it cannot widen unnoticed: this package knows no constructor
    // arguments for a publisher it ships no adapter entry for, so the container autowires it and the bound
    // EdaTracing reaches it whatever the key says. An application that wants the gate over its own publisher reads
    // BrokerTracing itself — which is exactly what the three shipped adapters do.
    [$container, $config] = relayEnv([
        'firefly.eda.postgres.relay.downstream_provider' => SpyDownstreamPublisher::class,
        'firefly.eda.tracing.brokers.enabled' => false,
    ]);
    $tracing = new StampingEdaTracing;
    $container->instance(EdaTracing::class, $tracing);

    $downstream = RelayDownstream::resolve($container, $config);

    expect((new ReflectionProperty($downstream, 'tracing'))->getValue($downstream))->toBe($tracing);
});
