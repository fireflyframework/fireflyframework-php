<?php

declare(strict_types=1);

namespace Firefly\Security\OAuth2\Server\Web;

use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * One protocol endpoint the filter dispatches to. `answersJson()` says how an unexpected failure is rendered: a
 * machine endpoint (token, introspection, revocation, userinfo, registration, JWKS, metadata) answers the RFC 6749
 * JSON document for an OAuth2AuthenticationException and `500 server_error` for anything else; a browser endpoint
 * (authorize, logout) renders its own refusals and lets anything else reach firefly/web's error page.
 */
interface OAuth2Endpoint
{
    /** @return list<string> */
    public function methods(): array;

    public function answersJson(): bool;

    public function handle(Request $request): Response;
}
