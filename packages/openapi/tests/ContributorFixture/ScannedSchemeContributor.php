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
 */
#[Component]
final class ScannedSchemeContributor implements SecuritySchemeContributor
{
    /**
     * @return list<SecurityScheme>
     */
    public function schemes(): array
    {
        return [new SecurityScheme('oauth2AuthorizationCode', ['type' => 'oauth2', 'flows' => ['authorizationCode' => []]])];
    }
}
