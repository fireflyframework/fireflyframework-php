<?php

declare(strict_types=1);

namespace Firefly\OpenApi\Tests\ContributorFixture;

use Firefly\Container\Attributes\Bean;
use Firefly\Container\Attributes\Configuration;
use Firefly\OpenApi\Security\SecurityRequirementContributor;

/**
 * The #[Bean] registration of a contributor, declared with the INTERFACE as its return type — which is what
 * makes the object findable at all, since the container tag getAll() reads is written only for scanned
 * components. A #[Bean] returning the concrete class would bind nothing under the interface and could not
 * be rescued by anything; the port docblocks say #[Component] for exactly that reason.
 */
#[Configuration]
final class AppContributorConfiguration
{
    #[Bean]
    public function beanRequirementContributor(): SecurityRequirementContributor
    {
        return new BeanRequirementContributor;
    }
}
