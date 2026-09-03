<?php

declare(strict_types=1);

namespace Firefly\OpenApi\Tests\Override;

use Firefly\Container\Attributes\Bean;
use Firefly\Container\Attributes\Configuration;
use Firefly\OpenApi\Web\ViewerPage;

/**
 * An application's own #[Configuration], standing in for "the team already ships a corporate API console".
 * It lives OUTSIDE tests/Fixture on purpose: the capstone scans that directory wholesale, and a
 * ViewerPage override discovered there would silently change what every other test renders.
 *
 * User #[Configuration]s enter the BeanDefinitionRegistry at BootPhase::UserConfigurations (300); the
 * package's own auto-configuration enters at AutoConfigurations (500) and its conditions are evaluated at
 * ConditionPassTwo (600) — which is exactly why #[ConditionalOnMissingBean] on the framework bean sees this
 * one and backs off. Getting that ordering wrong is the defect BootPhase's own docblock describes.
 */
#[Configuration]
final class AppOpenApiConfiguration
{
    #[Bean]
    public function viewerPage(): ViewerPage
    {
        return new ViewerPage('Corporate Console');
    }
}
