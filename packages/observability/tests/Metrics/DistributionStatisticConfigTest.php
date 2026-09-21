<?php

declare(strict_types=1);

use Firefly\Config\Config;
use Firefly\Kernel\Exception\Framework\ConfigurationException;
use Firefly\Observability\Metrics\DistributionStatisticConfig;
use Illuminate\Config\Repository as ConfigRepository;

/** @param array<string, mixed> $distribution */
function distributionConfig(array $distribution): DistributionStatisticConfig
{
    return DistributionStatisticConfig::fromConfig(new Config(new ConfigRepository(['firefly' => ['observability' => ['metrics' => ['distribution' => $distribution]]]])));
}

it('has no buckets by default, so every timer stays a summary', function () {
    expect(distributionConfig([])->bucketsFor('http_server_requests_seconds'))->toBe([])
        ->and(DistributionStatisticConfig::none()->bucketsFor('anything'))->toBe([]);
});

it('sorts, deduplicates and floats the global list, and a per-meter list overrides it — even to nothing', function () {
    $config = distributionConfig([
        'buckets' => [1, 0.1, '0.5', 0.1],
        'per-meter' => ['cqrs_commands_seconds' => [0.01, 0.05], 'db_query_seconds' => []],
    ]);

    expect($config->bucketsFor('http_server_requests_seconds'))->toBe([0.1, 0.5, 1.0])
        ->and($config->bucketsFor('cqrs_commands_seconds'))->toBe([0.01, 0.05])
        ->and($config->bucketsFor('db_query_seconds'))->toBe([]);
});

it('rejects a non-numeric or non-positive bound, naming the key', function () {
    expect(fn () => distributionConfig(['buckets' => [0.1, 'fast']]))->toThrow(ConfigurationException::class, 'distribution.buckets')
        ->and(fn () => distributionConfig(['buckets' => [0]]))->toThrow(ConfigurationException::class, 'positive')
        ->and(fn () => distributionConfig(['per-meter' => ['x' => [-1]]]))->toThrow(ConfigurationException::class, 'distribution.per-meter.x');
});

it('rejects a per-meter entry that is not a list', function () {
    expect(fn () => distributionConfig(['per-meter' => ['x' => 0.5]]))->toThrow(ConfigurationException::class, 'distribution.per-meter.x');
});
