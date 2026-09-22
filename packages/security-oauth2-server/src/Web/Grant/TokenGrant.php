<?php

declare(strict_types=1);

namespace Firefly\Security\OAuth2\Server\Web\Grant;

use Firefly\Security\OAuth2\Server\Client\AuthorizationGrantType;
use Firefly\Security\OAuth2\Server\Client\ClientAuthentication;
use Firefly\Security\OAuth2\Server\Error\OAuth2AuthenticationException;
use Firefly\Security\OAuth2\Server\Token\TokenIssuance;
use Illuminate\Http\Request;

/**
 * One grant the token endpoint dispatches to (Spring's per-grant AuthenticationProviders). It validates the
 * request for an ALREADY authenticated client and returns what to persist and what to answer; the endpoint
 * checks the client may use the grant, saves the authorization and renders the response.
 */
interface TokenGrant
{
    public function grantType(): AuthorizationGrantType;

    /**
     * @throws OAuth2AuthenticationException
     */
    public function grant(ClientAuthentication $client, Request $request): TokenIssuance;
}
