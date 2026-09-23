<?php

declare(strict_types=1);

use Firefly\Config\Config;
use Firefly\Eda\Tracing\BrokerTracing;
use Firefly\Eda\Tracing\EdaTracing;
use Firefly\Eda\Tracing\NoOpEdaTracing;
use Illuminate\Config\Repository;
use Illuminate\Container\Container;

/**
 * `firefly.eda.tracing.brokers.enabled` is a compliance control — "keep in-process spans while refusing to put
 * trace identifiers on a wire someone else reads" — and it lives in ONE place so it cannot be bypassed. It was
 * three private copies inside the adapters' #[Bean] methods, which gated the bean and nothing else: the relay
 * builds its downstream publisher through the container instead, and Illuminate falls back to a constructor
 * parameter's default only when the class is UNBOUND, so the real tracing was injected into
 * `?EdaTracing $tracing = null` regardless of the key. These cases pin the single decision every path now makes.
 */
/** @param array<string, mixed> $values */
function brokerConfig(array $values = []): Config
{
    return new Config(new Repository($values));
}

it('hands back the bound tracing when the key is absent (the default is on)', function () {
    $tracing = new NoOpEdaTracing;

    expect(BrokerTracing::resolve(brokerConfig(), $tracing))->toBe($tracing);
});

it('downgrades to the NoOp when the key is false', function () {
    $tracing = new NoOpEdaTracing;
    $config = brokerConfig(['firefly' => ['eda' => ['tracing' => ['brokers' => ['enabled' => false]]]]]);

    expect(BrokerTracing::resolve($config, $tracing))->not->toBe($tracing)
        ->and(BrokerTracing::resolve($config, $tracing))->toBeInstanceOf(NoOpEdaTracing::class);
});

it('never fails on a container with no EdaTracing bound at all', function () {
    $container = new Container;

    expect(BrokerTracing::bound($container))->toBeNull()
        ->and(BrokerTracing::forContainer($container, brokerConfig()))->toBeInstanceOf(NoOpEdaTracing::class);
});

it('reads the bound tracing out of a container, and still honours the key', function () {
    $tracing = new NoOpEdaTracing;
    $container = new Container;
    $container->instance(EdaTracing::class, $tracing);

    expect(BrokerTracing::bound($container))->toBe($tracing)
        ->and(BrokerTracing::forContainer($container, brokerConfig()))->toBe($tracing)
        ->and(BrokerTracing::forContainer($container, brokerConfig(['firefly' => ['eda' => ['tracing' => ['brokers' => ['enabled' => false]]]]])))
        ->toBeInstanceOf(NoOpEdaTracing::class);
});
