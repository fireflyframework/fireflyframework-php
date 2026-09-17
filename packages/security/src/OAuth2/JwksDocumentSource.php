<?php

declare(strict_types=1);

namespace Firefly\Security\OAuth2;

/**
 * The port an application that SIGNS ITS OWN TOKENS implements: the JWKS document it serves at its own
 * `/.well-known/jwks.json`, handed over in-process. Bind one and point `jwks_uri` at this application (or set
 * `jwks_source: local`) and SecurityAutoConfiguration wires LocalJwksProvider instead of fetching the keys over
 * HTTP from the process that is asking. See LocalJwksProvider for the deadlock that is the reason.
 */
interface JwksDocumentSource
{
    /**
     * @return array<string,mixed> a decoded JWKS document ({"keys": [...]})
     */
    public function jwks(): array;
}
