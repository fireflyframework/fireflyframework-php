<?php

declare(strict_types=1);

namespace Firefly\Security\OAuth2\Server\Web\Grant;

use Firefly\Container\Attributes\Component;
use Firefly\Context\Condition\Attributes\ConditionalOnProperty;
use Firefly\Security\OAuth2\Server\Authorization\OAuth2Authorization;
use Firefly\Security\OAuth2\Server\Client\AuthorizationGrantType;
use Firefly\Security\OAuth2\Server\Client\ClientAuthentication;
use Firefly\Security\OAuth2\Server\Client\ClientAuthenticationMethod;
use Firefly\Security\OAuth2\Server\Error\OAuth2AuthenticationException;
use Firefly\Security\OAuth2\Server\Error\OAuth2Error;
use Firefly\Security\OAuth2\Server\Error\OAuth2ErrorCodes;
use Firefly\Security\OAuth2\Server\Token\OAuth2TokenGenerator;
use Firefly\Security\OAuth2\Server\Token\TokenIssuance;
use Firefly\Security\OAuth2\Server\Web\TokenEndpoint;
use Illuminate\Http\Request;

/**
 * RFC 6749 §4.4: a CONFIDENTIAL client asks for a token in its own name — the principal is the client id, the
 * scopes are the ones asked for (every registered scope when none is) and must all be registered, and no refresh
 * token is issued (§4.4.3: SHOULD NOT). A public client here is `invalid_client`: nothing authenticated it.
 */
#[Component]
#[ConditionalOnProperty(name: 'firefly.security.enabled', havingValue: 'true')]
#[ConditionalOnProperty(name: 'firefly.security.oauth2.server.enabled', havingValue: 'true')]
final class ClientCredentialsGrant implements TokenGrant
{
    public function __construct(private readonly OAuth2TokenGenerator $tokens) {}

    public function grantType(): AuthorizationGrantType
    {
        return AuthorizationGrantType::ClientCredentials;
    }

    public function grant(ClientAuthentication $client, Request $request): TokenIssuance
    {
        if ($client->method === ClientAuthenticationMethod::None) {
            throw new OAuth2AuthenticationException(new OAuth2Error(OAuth2ErrorCodes::INVALID_CLIENT, 'The client_credentials grant needs a confidential client.'), 401);
        }

        $registered = $client->client;
        $scopes = TokenEndpoint::scopes($request) ?? $registered->scopes;
        if (! $registered->hasScopes($scopes)) {
            throw new OAuth2AuthenticationException(new OAuth2Error(OAuth2ErrorCodes::INVALID_SCOPE, 'The request asks for a scope the client did not register.'));
        }

        $authorization = OAuth2Authorization::create($registered, $registered->clientId, AuthorizationGrantType::ClientCredentials, $scopes);

        return $this->tokens->issue($authorization, $registered, false);
    }
}
