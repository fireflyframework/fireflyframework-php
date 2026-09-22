<?php

declare(strict_types=1);

namespace Firefly\Security\OAuth2\Server\Web\Grant;

use DateTimeImmutable;
use Firefly\Container\Attributes\Component;
use Firefly\Context\Condition\Attributes\ConditionalOnProperty;
use Firefly\Security\Authentication\Exception\UsernameNotFoundException;
use Firefly\Security\Core\Authentication;
use Firefly\Security\OAuth2\Server\Authorization\OAuth2AuthorizationService;
use Firefly\Security\OAuth2\Server\Authorization\OAuth2TokenType;
use Firefly\Security\OAuth2\Server\Client\AuthorizationGrantType;
use Firefly\Security\OAuth2\Server\Client\ClientAuthentication;
use Firefly\Security\OAuth2\Server\Client\ClientAuthenticationMethod;
use Firefly\Security\OAuth2\Server\Error\OAuth2AuthenticationException;
use Firefly\Security\OAuth2\Server\Error\OAuth2Error;
use Firefly\Security\OAuth2\Server\Error\OAuth2ErrorCodes;
use Firefly\Security\OAuth2\Server\Pkce\ProofKey;
use Firefly\Security\OAuth2\Server\Settings\AuthorizationServerSettings;
use Firefly\Security\OAuth2\Server\Token\OAuth2TokenGenerator;
use Firefly\Security\OAuth2\Server\Token\TokenIssuance;
use Firefly\Security\User\UserDetailsService;
use Illuminate\Http\Request;
use Psr\Log\LoggerInterface;

/**
 * RFC 6749 §4.1.3 with RFC 7636 §4.6: the code must exist (by hash), belong to the authenticated client, be
 * unused — a REUSED code revokes every token the authorization holds, as §4.1.2 says a server SHOULD — and
 * unexpired; `redirect_uri` must equal the one the authorization request carried; `code_verifier` must match
 * the stored challenge (constant-time) — and a public client always needs one. Every refusal is
 * `invalid_grant`, one sentence, never the code or the verifier. On success the code is invalidated, the tokens
 * are minted (a refresh token only for a confidential client with the refresh_token grant) and the customizer
 * sees the user as UserDetailsService knows them (authorities included) when the store still has them.
 */
#[Component]
#[ConditionalOnProperty(name: 'firefly.security.enabled', havingValue: 'true')]
#[ConditionalOnProperty(name: 'firefly.security.oauth2.server.enabled', havingValue: 'true')]
final class AuthorizationCodeGrant implements TokenGrant
{
    public function __construct(
        private readonly OAuth2AuthorizationService $authorizations,
        private readonly OAuth2TokenGenerator $tokens,
        private readonly AuthorizationServerSettings $settings,
        private readonly ?UserDetailsService $users = null,
        private readonly ?LoggerInterface $logger = null,
    ) {}

    public function grantType(): AuthorizationGrantType
    {
        return AuthorizationGrantType::AuthorizationCode;
    }

    public function grant(ClientAuthentication $client, Request $request): TokenIssuance
    {
        $code = $request->input('code');
        if (! is_string($code) || $code === '') {
            throw new OAuth2AuthenticationException(new OAuth2Error(OAuth2ErrorCodes::INVALID_REQUEST, 'code is required.'));
        }

        $authorization = $this->authorizations->findByToken($code, OAuth2TokenType::AuthorizationCode);
        $token = $authorization?->token(OAuth2TokenType::AuthorizationCode);
        if ($authorization === null || $token === null || $authorization->registeredClientId !== $client->client->id) {
            throw $this->refuse('The authorization code is unknown or was not issued to this client.');
        }

        if ($token->isInvalidated()) {
            $this->authorizations->save($authorization->withEveryTokenInvalidated());
            $this->logger?->warning("OAuth2 authorization code REUSED by client [{$client->client->clientId}]: every token of the authorization was revoked.");
            throw $this->refuse('The authorization code was already used; the tokens it issued have been revoked.');
        }
        if ($token->isExpired(new DateTimeImmutable)) {
            $this->authorizations->save($authorization->withInvalidatedToken(OAuth2TokenType::AuthorizationCode));
            throw $this->refuse('The authorization code has expired.');
        }

        $redirectUri = $request->input('redirect_uri');
        if (! is_string($redirectUri) || $redirectUri !== $authorization->attribute('redirect_uri')) {
            throw $this->refuse('redirect_uri does not match the authorization request.');
        }

        $challenge = $authorization->attribute('code_challenge');
        if (is_string($challenge)) {
            $verifier = $request->input('code_verifier');
            if (! is_string($verifier) || ! ProofKey::verify($verifier, $challenge)) {
                throw $this->refuse('code_verifier is missing or does not match the code_challenge.');
            }
        } elseif ($client->method === ClientAuthenticationMethod::None || $client->client->requiresProofKey($this->settings)) {
            throw $this->refuse('The authorization code was issued without PKCE, which this client must use.');
        }

        $authorization = $authorization->withInvalidatedToken(OAuth2TokenType::AuthorizationCode);
        $withRefresh = $client->client->supportsGrant(AuthorizationGrantType::RefreshToken) && $client->method !== ClientAuthenticationMethod::None;

        return $this->tokens->issue($authorization, $client->client, $withRefresh, $this->principal($authorization->principalName));
    }

    /** The user as the store knows them now (authorities for the customizer), or a bare name when it does not. */
    private function principal(string $name): Authentication
    {
        if ($this->users !== null) {
            try {
                $user = $this->users->loadUserByUsername($name);

                return Authentication::authenticated($name, $user, $user->getAuthorities());
            } catch (UsernameNotFoundException) {
                // A user removed since the code was issued still gets the token the grant promised; the name is what the token names.
            }
        }

        return Authentication::authenticated($name, $name, []);
    }

    private function refuse(string $description): OAuth2AuthenticationException
    {
        return new OAuth2AuthenticationException(new OAuth2Error(OAuth2ErrorCodes::INVALID_GRANT, $description));
    }
}
