<?php

declare(strict_types=1);

use Firefly\Config\Config;
use Firefly\Eda\Bus\SubscriberRegistry;
use Firefly\Eda\Database\DatabaseEventPublisher;
use Firefly\Eda\Database\Outbox\RelayDownstream;
use Firefly\Eda\Database\Tests\Fixtures\RecordingDownstream;
use Firefly\Kernel\Exception\Framework\ConfigurationException;
use Illuminate\Config\Repository;
use Illuminate\Container\Container;
use Illuminate\Database\SQLiteConnection;

/**
 * @param  array<string, mixed>  $values  dotted key => value
 * @return array{Container, Config}
 */
function downstreamEnv(array $values): array
{
    $repository = new Repository;
    foreach ($values as $key => $value) {
        $repository->set($key, $value);
    }

    return [new Container, new Config($repository)];
}

it('resolves the new key, and the legacy Postgres key as its alias', function () {
    [$container, $config] = downstreamEnv(['firefly.eda.database.relay.downstream-provider' => RecordingDownstream::class]);
    expect(RelayDownstream::resolve($container, $config))->toBeInstanceOf(RecordingDownstream::class);

    [$container, $config] = downstreamEnv(['firefly.eda.postgres.relay.downstream_provider' => RecordingDownstream::class]);
    expect(RelayDownstream::configuredProvider($config))->toBe(RecordingDownstream::class)
        ->and(RelayDownstream::resolve($container, $config))->toBeInstanceOf(RecordingDownstream::class);
});

it('names both keys and the in-process alternative when neither is set', function () {
    [$container, $config] = downstreamEnv([]);

    expect(fn () => RelayDownstream::resolve($container, $config))
        ->toThrow(ConfigurationException::class, 'firefly.eda.database.relay.downstream-provider')
        ->and(fn () => RelayDownstream::resolve($container, $config))
        ->toThrow(ConfigurationException::class, 'firefly.eda.postgres.relay.downstream_provider');
});

it('refuses the database outbox writer, however it was named', function () {
    [$container, $config] = downstreamEnv(['firefly.eda.database.relay.downstream-provider' => 'rabbitmq']);
    $container->instance(RelayDownstream::BINDING, new DatabaseEventPublisher(new SQLiteConnection(new PDO('sqlite::memory:'), ':memory:'), new SubscriberRegistry));

    expect(fn () => RelayDownstream::resolve($container, $config))->toThrow(ConfigurationException::class, 'outbox writer');
});
