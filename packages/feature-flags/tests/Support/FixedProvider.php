<?php

declare(strict_types=1);

namespace Firefly\FeatureFlags\Tests\Support;

use OpenFeature\implementation\provider\AbstractProvider;
use OpenFeature\implementation\provider\ResolutionDetailsBuilder;
use OpenFeature\interfaces\flags\EvaluationContext;
use OpenFeature\interfaces\provider\ResolutionDetails;

/** An external provider: every boolean flag is on. */
final class FixedProvider extends AbstractProvider
{
    protected static string $NAME = 'fixed-vendor';

    public function resolveBooleanValue(string $flagKey, bool $defaultValue, ?EvaluationContext $context = null): ResolutionDetails
    {
        return (new ResolutionDetailsBuilder)->withValue(true)->withVariant('vendor-on')->withReason('STATIC')->build();
    }

    public function resolveStringValue(string $flagKey, string $defaultValue, ?EvaluationContext $context = null): ResolutionDetails
    {
        return (new ResolutionDetailsBuilder)->withValue($defaultValue)->withReason('DEFAULT')->build();
    }

    public function resolveIntegerValue(string $flagKey, int $defaultValue, ?EvaluationContext $context = null): ResolutionDetails
    {
        return (new ResolutionDetailsBuilder)->withValue($defaultValue)->withReason('DEFAULT')->build();
    }

    public function resolveFloatValue(string $flagKey, float $defaultValue, ?EvaluationContext $context = null): ResolutionDetails
    {
        return (new ResolutionDetailsBuilder)->withValue($defaultValue)->withReason('DEFAULT')->build();
    }

    public function resolveObjectValue(string $flagKey, array $defaultValue, ?EvaluationContext $context = null): ResolutionDetails
    {
        return (new ResolutionDetailsBuilder)->withValue($defaultValue)->withReason('DEFAULT')->build();
    }
}
