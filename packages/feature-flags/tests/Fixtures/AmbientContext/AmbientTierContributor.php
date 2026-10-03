<?php

declare(strict_types=1);

namespace Firefly\FeatureFlags\Tests\Fixtures\AmbientContext;

use Firefly\Container\Attributes\Component;
use Firefly\FeatureFlags\Context\EvaluationContextBuilder;
use Firefly\FeatureFlags\Context\EvaluationContextContributor;

/** An application contributor standing in for "who is calling": a preview must never see it (I-3). */
#[Component]
final class AmbientTierContributor implements EvaluationContextContributor
{
    public function contribute(EvaluationContextBuilder $context): void
    {
        $context->setTargetingKey('ambient-user');
        $context->set('tier', 'gold');
    }
}
