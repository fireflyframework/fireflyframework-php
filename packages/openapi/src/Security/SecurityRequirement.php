<?php

declare(strict_types=1);

namespace Firefly\OpenApi\Security;

/**
 * One entry of an operation's `security` array: the name of a scheme declared in `components.securitySchemes`,
 * and the scopes a caller must hold under it.
 *
 * The scope list is EMPTY for every scheme that is not `oauth2`/`openIdConnect`, and the specification is
 * explicit that it must be — an `http` bearer scheme has no scope vocabulary of its own, so a non-empty list
 * beside one is invalid. That is why `hasRole:`/`hasAuthority:` rules contribute a bare requirement while a
 * `hasScope:` rule contributes its scope: a role is an authority this document has nowhere to put, and
 * inventing `['ROLE_ADMIN']` as a scope would publish a vocabulary the token endpoint has never heard of.
 */
final readonly class SecurityRequirement
{
    /**
     * @param  list<string>  $scopes
     */
    public function __construct(
        public string $scheme,
        public array $scopes = [],
    ) {}

    /**
     * @return array<string, list<string>>
     */
    public function toArray(): array
    {
        return [$this->scheme => $this->scopes];
    }
}
