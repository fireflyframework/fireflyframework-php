<?php

declare(strict_types=1);

namespace Firefly\FeatureFlags\Evaluation;

use Firefly\Container\Attributes\Bean;
use Firefly\Container\Attributes\Configuration;
use Firefly\Container\Attributes\Order;
use Firefly\Context\Condition\Attributes\ConditionalOnMissingBean;
use Firefly\Context\Condition\Attributes\ConditionalOnProperty;

/**
 * The in-process flagd evaluator. Its own #[Configuration] so lane L1a owns it without touching
 * FeatureFlagsAutoConfiguration; an application that supplies its own FlagdEvaluator bean wins.
 */
#[Configuration]
#[Order(700)]
final class FlagdEvaluatorConfiguration
{
    #[Bean]
    #[ConditionalOnProperty(name: 'firefly.feature-flags.enabled', havingValue: 'true')]
    #[ConditionalOnMissingBean(FlagdEvaluator::class)]
    public function flagdEvaluator(): FlagdEvaluator
    {
        return new DefaultFlagdEvaluator;
    }
}
