<?php

declare(strict_types=1);

namespace Firefly\OpenApi\Security;

/**
 * One entry of an operation's `security` array: the name of a scheme declared in `components.securitySchemes`,
 * and the scopes a caller must hold under it.
 *
 * WHAT MAY GO IN THE LIST is scheme-dependent, and 3.1 is looser than 3.0 was: for an `oauth2`/
 * `openIdConnect` scheme the entries are scope names the token must carry, and for every other type the
 * array MAY carry names "required for the execution, but not otherwise defined or exchanged in-band" —
 * which is exactly what a `SCOPE_x` authority in a bearer token is to this framework. That is why a
 * `hasScope:` rule contributes its scope beside the `type: http, scheme: bearer` resource-server entry,
 * while `hasRole:`/`hasAuthority:` rules contribute a bare requirement: a scope is a thing a client asks
 * the token endpoint for, and a role is not — publishing `['ROLE_ADMIN']` here would name a vocabulary no
 * token endpoint has ever heard of, and a generated client would try to request it.
 *
 * An empty list is therefore not a claim that nothing is required. It is this document saying the credential
 * is the whole of what it can state, and the 403 an under-privileged caller gets is a runtime fact no
 * `security` array was ever able to express.
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
