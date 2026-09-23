<?php

declare(strict_types=1);

namespace Firefly\OpenApi\Tests\ContributorFixture;

use Firefly\Container\Attributes\Component;
use Firefly\OpenApi\Security\SecurityScheme;
use Firefly\OpenApi\Security\SecuritySchemeContributor;

/**
 * A contributor registered the way the port docblocks say to register one: a plain #[Component], which the
 * scanner tags `firefly.contract.<interface>` and Container::getAll() therefore finds. Stands in for a
 * package that knows facts `firefly.security.*` cannot state — an authorization server's flow URLs, say.
 *
 * It declares DEFAULT SCOPES too, so the container test beside it also pins what happens when a requirement
 * contributor names this scheme: BeanRequirementContributor states `orders.read` of its own, and keeps it.
 * The default is inherited only by a requirement that states nothing.
 */
#[Component]
final class ScannedSchemeContributor implements SecuritySchemeContributor
{
    /**
     * @return list<SecurityScheme>
     */
    public function schemes(): array
    {
        return [new SecurityScheme('oauth2AuthorizationCode', ['type' => 'oauth2', 'flows' => ['authorizationCode' => []]], ['orders.read', 'orders.write'])];
    }
}
