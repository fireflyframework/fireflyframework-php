<?php

declare(strict_types=1);

namespace Firefly\Security\OAuth2\Server\Jose;

use Firefly\Security\OAuth2\JwksDocumentSource;

/**
 * The security core's JwksDocumentSource port, answered by this server's keys: bound as a bean, so
 * SecurityAutoConfiguration's jwksProvider() picks LocalJwksProvider (`jwks_source: local`, or `auto` when
 * `jwks_uri` names this application) and OAuth2ResourceServerFilter verifies the tokens this server issues
 * in-process — the same application as its own resource server, no HTTP self-fetch, no cache to depend on.
 * The JWKS endpoint serves the same bytes to every other resource server.
 */
final class AuthorizationServerJwksDocumentSource implements JwksDocumentSource
{
    public function __construct(private readonly JwtSigningKeys $keys) {}

    /**
     * @return array<string,mixed>
     */
    public function jwks(): array
    {
        return $this->keys->jwks();
    }
}
