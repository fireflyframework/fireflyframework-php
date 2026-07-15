<?php

declare(strict_types=1);

namespace Firefly\Context\Tests\IntegrationFixtures;

use Firefly\Container\Attributes\Component;
use Firefly\Context\Condition\Attributes\ConditionalOnProperty;

/**
 * Gated on a config property IntegrationTest deliberately never sets — must be filtered out of the
 * BeanDefinitionRegistry before FlushDefinitionsPass and never bound in the container.
 */
#[Component]
#[ConditionalOnProperty(name: 'firefly.feature.off')]
final class GatedComponentRemoved {}
