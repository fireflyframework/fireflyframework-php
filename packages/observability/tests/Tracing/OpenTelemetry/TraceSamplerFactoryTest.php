<?php

declare(strict_types=1);

use Firefly\Config\Config;
use Firefly\Kernel\Exception\Framework\ConfigurationException;
use Firefly\Observability\Tracing\OpenTelemetry\TraceSamplerFactory;
use Illuminate\Config\Repository as ConfigRepository;

/** @param array<string, mixed> $sampler */
function samplerConfig(array $sampler): Config
{
    return new Config(new ConfigRepository(['firefly' => ['observability' => ['tracing' => ['sampler' => $sampler]]]]));
}

it('defaults to a parent-based always-on sampler', function () {
    expect(TraceSamplerFactory::fromConfig(samplerConfig([]))->getDescription())->toBe('ParentBased+AlwaysOnSampler');
});

it('builds always_off and ratio samplers', function () {
    expect(TraceSamplerFactory::fromConfig(samplerConfig(['type' => 'always_off']))->getDescription())->toBe('ParentBased+AlwaysOffSampler')
        ->and(TraceSamplerFactory::fromConfig(samplerConfig(['type' => 'ratio', 'ratio' => 0.25]))->getDescription())->toBe('ParentBased+TraceIdRatioBasedSampler{0.250000}');
});

it('rejects an unknown sampler type', function () {
    expect(fn () => TraceSamplerFactory::fromConfig(samplerConfig(['type' => 'coin-flip'])))
        ->toThrow(ConfigurationException::class, "Unknown tracing sampler 'coin-flip'");
});

it('rejects a ratio outside 0..1', function () {
    expect(fn () => TraceSamplerFactory::fromConfig(samplerConfig(['type' => 'ratio', 'ratio' => 7])))
        ->toThrow(ConfigurationException::class, 'sampler.ratio');
});
