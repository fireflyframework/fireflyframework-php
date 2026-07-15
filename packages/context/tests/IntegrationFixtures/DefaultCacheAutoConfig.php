<?php

declare(strict_types=1);

namespace Firefly\Context\Tests\IntegrationFixtures;

use Firefly\Container\Attributes\Component;
use Firefly\Context\Condition\Attributes\ConditionalOnMissingBean;

/**
 * A stand-in for what M5's AutoConfigDiscovery will one day scan: a fallback CachePort provider
 * that should only be registered when no user bean already supplies CachePort. IntegrationTest
 * feeds this ComponentDescriptor into the BeanDefinitionRegistry tagged
 * DefinitionSource::AutoConfiguration (the #[ConditionalOnMissingBean] attribute below is illegal
 * on a DefinitionSource::User definition — see ConditionEvaluator's user-component rule).
 */
#[Component]
#[ConditionalOnMissingBean(CachePort::class)]
final class DefaultCacheAutoConfig implements CachePort {}
