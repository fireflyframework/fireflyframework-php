<?php

declare(strict_types=1);

namespace Firefly\Security\OAuth2\Server\Web\Grant;

/** The grants the token endpoint knows, by their `grant_type` value; built by the auto-configuration. */
final class TokenGrants
{
    /** @var array<string,TokenGrant> */
    private array $byType = [];

    /**
     * @param  list<TokenGrant>  $grants
     */
    public function __construct(array $grants)
    {
        foreach ($grants as $grant) {
            $this->byType[$grant->grantType()->value] = $grant;
        }
    }

    public function for(string $grantType): ?TokenGrant
    {
        return $this->byType[$grantType] ?? null;
    }
}
