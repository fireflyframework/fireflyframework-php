<?php

declare(strict_types=1);

namespace Firefly\Security\OAuth2\Server\Web\Grant;

use DateTimeImmutable;
use Firefly\Container\Attributes\Component;
use Firefly\Context\Condition\Attributes\ConditionalOnProperty;
use Firefly\Security\Authentication\Exception\UsernameNotFoundException;
use Firefly\Security\Core\Authentication;
use Firefly\Security\OAuth2\Server\Authorization\OAuth2Authorization;
use Firefly\Security\OAuth2\Server\Authorization\OAuth2AuthorizationService;
use Firefly\Security\OAuth2\Server\Authorization\OAuth2TokenType;
use Firefly\Security\OAuth2\Server\Authorization\TokenHash;
use Firefly\Security\OAuth2\Server\Client\AuthorizationGrantType;
use Firefly\Security\OAuth2\Server\Client\ClientAuthentication;
use Firefly\Security\OAuth2\Server\Client\ClientAuthenticationMethod;
use Firefly\Security\OAuth2\Server\Error\OAuth2AuthenticationException;
use Firefly\Security\OAuth2\Server\Error\OAuth2Error;
use Firefly\Security\OAuth2\Server\Error\OAuth2ErrorCodes;
use Firefly\Security\OAuth2\Server\Token\OAuth2AccessTokenResponse;
use Firefly\Security\OAuth2\Server\Token\OAuth2TokenGenerator;
use Firefly\Security\OAuth2\Server\Token\TokenIssuance;
use Firefly\Security\OAuth2\Server\Web\TokenEndpoint;
use Firefly\Security\User\UserDetailsService;
use Illuminate\Http\Request;
use Psr\Log\LoggerInterface;

/**
 * RFC 6749 §6 (Spring's OAuth2RefreshTokenAuthenticationProvider): the token must be found by hash — the
 * CURRENT refresh token of an authorization, or one in its `refresh_token_family` — belong to the authenticated
 * confidential client, be unrevoked and unexpired; a requested `scope` may only narrow the original grant.
 *
 * ROTATION AND REUSE DETECTION (OAuth 2.1 §4.3.1 / RFC 9700 §4.14.2): with the client's `reuse_refresh_tokens`
 * off, every refresh issues a NEW refresh token, the previous access token is invalidated, and the superseded
 * hash joins the family. A refresh token that is found in the family but is NOT the current one has been
 * presented twice — by the client that lost it or by whoever found it — so the whole authorization is removed
 * (every token, current refresh token included) and the answer is `invalid_grant`. With reuse on, the token
 * the client presented is what it gets back (the server holds no value to echo) and nothing joins the family.
 * The stored grant keeps its ORIGINAL scopes; a narrowed access token records its own.
 */
#[Component]
#[ConditionalOnProperty(name: 'firefly.security.enabled', havingValue: 'true')]
#[ConditionalOnProperty(name: 'firefly.security.oauth2.server.enabled', havingValue: 'true')]
final class RefreshTokenGrant implements TokenGrant
{
    public function __construct(
        private readonly OAuth2AuthorizationService $authorizations,
        private readonly OAuth2TokenGenerator $tokens,
        private readonly ?UserDetailsService $users = null,
        private readonly ?LoggerInterface $logger = null,
    ) {}

    public function grantType(): AuthorizationGrantType
    {
        return AuthorizationGrantType::RefreshToken;
    }

    public function grant(ClientAuthentication $client, Request $request): TokenIssuance
    {
        $presented = $request->input('refresh_token');
        if (! is_string($presented) || $presented === '') {
            throw new OAuth2AuthenticationException(new OAuth2Error(OAuth2ErrorCodes::INVALID_REQUEST, 'refresh_token is required.'));
        }
        if ($client->method === ClientAuthenticationMethod::None) {
            throw new OAuth2AuthenticationException(new OAuth2Error(OAuth2ErrorCodes::INVALID_CLIENT, 'The refresh_token grant needs a confidential client.'), 401);
        }

        $authorization = $this->authorizations->findByToken($presented, OAuth2TokenType::RefreshToken);
        $current = $authorization?->token(OAuth2TokenType::RefreshToken);
        if ($authorization === null || $current === null || $authorization->registeredClientId !== $client->client->id) {
            throw $this->refuse('The refresh token is unknown or was not issued to this client.');
        }

        if (! hash_equals($current->hash, TokenHash::of($presented))) {
            $this->authorizations->remove($authorization);
            $this->logger?->warning("OAuth2 refresh token REUSED by client [{$client->client->clientId}] for [{$authorization->principalName}]: the authorization was revoked.");
            throw $this->refuse('The refresh token was already rotated; the authorization has been revoked.');
        }
        if ($current->isInvalidated()) {
            throw $this->refuse('The refresh token has been revoked.');
        }
        if ($current->isExpired(new DateTimeImmutable)) {
            throw $this->refuse('The refresh token has expired.');
        }

        $requested = TokenEndpoint::scopes($request);
        $scopes = $requested ?? $authorization->authorizedScopes;
        foreach ($scopes as $scope) {
            if (! in_array($scope, $authorization->authorizedScopes, true)) {
                throw new OAuth2AuthenticationException(new OAuth2Error(OAuth2ErrorCodes::INVALID_SCOPE, 'The requested scope is wider than the original grant.'));
            }
        }

        $reuse = $client->client->tokenSettings->reuseRefreshTokens;
        $authorization = $authorization->withInvalidatedToken(OAuth2TokenType::AccessToken);
        if (! $reuse) {
            $authorization = $authorization->withSupersededRefreshToken($current->hash);
        }

        $forIssue = new OAuth2Authorization($authorization->id, $authorization->registeredClientId, $authorization->principalName, $authorization->authorizationGrantType, $scopes, $authorization->attributes, $authorization->tokens);
        $issued = $this->tokens->issue($forIssue, $client->client, ! $reuse, $this->principal($authorization->principalName));

        $stored = new OAuth2Authorization($issued->authorization->id, $issued->authorization->registeredClientId, $issued->authorization->principalName, $issued->authorization->authorizationGrantType, $authorization->authorizedScopes, $issued->authorization->attributes, $issued->authorization->tokens);
        $response = $reuse
            ? new OAuth2AccessTokenResponse($issued->response->accessToken, $issued->response->expiresIn, $issued->response->scopes, $presented, $issued->response->idToken)
            : $issued->response;

        return new TokenIssuance($stored, $response);
    }

    /** The user as the store knows them now (authorities for the customizer), or a bare name when it does not. */
    private function principal(string $name): Authentication
    {
        if ($this->users !== null) {
            try {
                $user = $this->users->loadUserByUsername($name);

                return Authentication::authenticated($name, $user, $user->getAuthorities());
            } catch (UsernameNotFoundException) {
                // The grant outlives the account record; the token names what the grant named.
            }
        }

        return Authentication::authenticated($name, $name, []);
    }

    private function refuse(string $description): OAuth2AuthenticationException
    {
        return new OAuth2AuthenticationException(new OAuth2Error(OAuth2ErrorCodes::INVALID_GRANT, $description));
    }
}
