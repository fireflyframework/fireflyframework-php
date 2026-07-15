<?php

declare(strict_types=1);

namespace Firefly\Context\Tests\IntegrationFixtures;

use Firefly\Container\Attributes\Component;
use Firefly\Context\Condition\Attributes\ConditionalOnProperty;

/**
 * Gated on a config property IntegrationTest sets to a truthy value — must survive both condition
 * passes and remain resolvable after boot.
 */
#[Component]
#[ConditionalOnProperty(name: 'firefly.feature.on')]
final class GatedComponentKept {}
