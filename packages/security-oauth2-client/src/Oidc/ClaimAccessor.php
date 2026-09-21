<?php

declare(strict_types=1);

namespace Firefly\Security\OAuth2\Client\Oidc;

/** Read access to a set of claims (Spring's ClaimAccessor): the id token, the userinfo, and the principal that merges both. */
interface ClaimAccessor
{
    /** @return array<string, mixed> */
    public function getClaims(): array;

    public function getClaim(string $name): mixed;

    public function hasClaim(string $name): bool;
}
