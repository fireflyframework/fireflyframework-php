<?php

declare(strict_types=1);

namespace Firefly\Security\OAuth2;

use Firebase\JWT\Key;

/**
 * The resource server's signing keys from THIS process, for an application that mints and verifies its own
 * tokens.
 *
 * THE SELF-FETCH, AND WHY IT TOOK A DEV STACK DOWN. An application that serves `/.well-known/jwks.json` for
 * the service tokens it mints points `jwks_uri` at itself so those tokens verify. RemoteJwksProvider fetched
 * that URI over HTTP and cached the answer in Laravel's cache — which a local environment sets to `array`, a
 * store that lives exactly one request. So EVERY authenticated request made a nested HTTP request to the same
 * single-process server, needed a second free worker to answer it, and blocked until one appeared; with a
 * console tab holding a long-lived SSE stream the pool ran out, the outer requests sat in curl until PHP's
 * thirty-second limit killed them, and ordinary GETs answered "Maximum execution time of 30 seconds
 * exceeded". Measured before the fix: 844 of 3,000 requests were the JWKS fetch, one per authenticated call.
 *
 * WHEN THE KEYS ARE HERE, THE ANSWER IS HERE. The application binds a JwksDocumentSource — the same bytes its
 * route serves — and this provider asks it directly: no socket, no second worker, no cache store to depend
 * on. SecurityAutoConfiguration chooses it when `jwks_source` is `local`, or when it is `auto` (the default)
 * and JwksUri::isOwn() says the configured URI names this application; a deployment pointed at a foreign
 * issuer keeps the remote path, so an in-process key is an accepted signer ONLY where the URI already said so.
 */
final class LocalJwksProvider implements JwksProvider
{
    public function __construct(private readonly JwksDocumentSource $source) {}

    /**
     * @return array<string,Key>
     */
    public function keys(): array
    {
        return InMemoryJwksProvider::fromJwks($this->source->jwks())->keys();
    }
}
