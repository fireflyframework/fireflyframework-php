<?php

declare(strict_types=1);

namespace Firefly\OpenApi\Tests\ContributorFixture;

use Firefly\OpenApi\Security\SecurityRequirement;
use Firefly\OpenApi\Security\SecurityRequirementContributor;
use Firefly\Web\Route\RouteDescriptor;

/**
 * The contributor the #[Configuration] beside this class registers as a #[Bean]. #[Bean]-produced objects
 * are never tagged, so this one reaches the model only through the interface binding its factory's return
 * type creates — the rescue path OpenApiAutoConfiguration::securityModel() keeps so that a seam used the
 * second-most-obvious way still works instead of silently contributing nothing.
 */
final class BeanRequirementContributor implements SecurityRequirementContributor
{
    /**
     * @return list<SecurityRequirement>
     */
    public function requirementsFor(RouteDescriptor $route): array
    {
        return [new SecurityRequirement('oauth2AuthorizationCode', ['orders.read'])];
    }
}
