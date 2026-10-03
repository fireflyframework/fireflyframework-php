<?php

declare(strict_types=1);

namespace Firefly\FeatureFlags\Tests\Support;

use Firefly\Config\Config;
use Firefly\Container\Container as FireflyContainer;
use Firefly\Container\Scanner\ComponentManifest;
use Firefly\FeatureFlags\Context\EvaluationContextResolver;
use Firefly\FeatureFlags\Evaluation\DefaultFlagdEvaluator;
use Firefly\FeatureFlags\FeatureFlags;
use Firefly\FeatureFlags\Gating\FeatureFlagGate;
use Firefly\FeatureFlags\Provider\FireflyFlagProvider;
use Illuminate\Config\Repository;
use Illuminate\Container\Container;
use OpenFeature\isolated\OpenFeatureAPIFactory;

final class GateFixtures
{
    /** @param array<string, mixed> $settings */
    public static function gate(bool $withFlags = true, array $settings = []): FeatureFlagGate
    {
        $illuminate = new Container;
        if ($withFlags) {
            $document = StaticDocument::json('{"flags":{
                "on":{"state":"ENABLED","variants":{"on":true,"off":false},"defaultVariant":"on"},
                "off":{"state":"ENABLED","variants":{"on":true,"off":false},"defaultVariant":"off"},
                "flow":{"state":"ENABLED","variants":{"v1":"v1","v2":"v2"},"defaultVariant":"v2"},
                "paused":{"state":"DISABLED","variants":{"on":true},"defaultVariant":"on"},
                "unset":{"state":"ENABLED","variants":{"on":true}}
            }}');
            $flags = new FeatureFlags(new FireflyFlagProvider($document, new DefaultFlagdEvaluator), new EvaluationContextResolver, [], $document, OpenFeatureAPIFactory::createAPI());
            $illuminate->instance(FeatureFlags::class, $flags);
        }

        return new FeatureFlagGate(
            new FireflyContainer($illuminate, new ComponentManifest([])),
            new Config(new Repository(['firefly' => ['feature-flags' => $settings]])),
        );
    }
}
