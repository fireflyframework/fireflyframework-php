<?php

declare(strict_types=1);

namespace Firefly\Security\OAuth2\Client\Web\Login;

use Firefly\Security\OAuth2\Client\Authorized\OAuth2AuthorizedClient;
use Firefly\Security\OAuth2\Client\Oidc\OidcIdTokenDecoderFactory;
use Firefly\Security\OAuth2\Client\Oidc\OidcIdTokenValidator;
use Firefly\Security\OAuth2\Client\Registration\ClientRegistration;
use Firefly\Security\OAuth2\Client\Token\OAuth2AccessTokenResponseClient;
use Firefly\Security\OAuth2\Client\Token\OAuth2AuthenticationException;
use Firefly\Security\OAuth2\Client\Token\OAuth2AuthorizationException;
use Firefly\Security\OAuth2\Client\Token\OAuth2Error;
use Firefly\Security\OAuth2\Client\Token\OAuth2ErrorCodes;
use Firefly\Security\OAuth2\Client\User\GrantedAuthoritiesMapper;
use Firefly\Security\OAuth2\Client\User\OAuth2AuthenticationToken;
use Firefly\Security\OAuth2\Client\User\OAuth2UserRequest;
use Firefly\Security\OAuth2\Client\User\OAuth2UserService;
use Firefly\Security\OAuth2\Client\User\OidcUserRequest;
use Firefly\Security\OAuth2\Client\User\OidcUserService;
use Firefly\Security\OAuth2\Client\Web\OAuth2AuthorizationRequest;

/**
 * The second half of the login, from a code the filter has already tied to an authorization request (Spring's
 * OidcAuthorizationCodeAuthenticationProvider and OAuth2LoginAuthenticationProvider in one):
 *
 *   1. the code is exchanged at the token endpoint with the request's redirect_uri and PKCE verifier (a
 *      refused exchange — an OAuth2AuthorizationException — becomes an OAuth2AuthenticationException with the
 *      same RFC code, because for the person it is a refused login);
 *   2. an `openid` registration MUST get an id_token back, which is decoded (signature, exp) against the
 *      provider's JWKS and validated (iss, aud, azp, iat, sub, nonce) — then the OidcUserService loads the
 *      principal; any other registration goes to the OAuth2UserService, whose only source is userinfo;
 *   3. the GrantedAuthoritiesMapper, when one is bound, maps the authorities the service granted; the
 *      Authentication carries the mapped list, the principal keeps the granted one (Spring's split);
 *   4. the authorized client is built from the token response, keyed by the principal's name, with the raw id
 *      token for the RP-initiated logout.
 */
final class OAuth2LoginAuthenticationProvider
{
    public function __construct(
        private readonly OAuth2AccessTokenResponseClient $tokens,
        private readonly OidcIdTokenDecoderFactory $decoders,
        private readonly OidcUserService $oidcUsers,
        private readonly OAuth2UserService $oauth2Users,
        private readonly ?GrantedAuthoritiesMapper $authoritiesMapper = null,
    ) {}

    public function authenticate(ClientRegistration $registration, OAuth2AuthorizationRequest $authorizationRequest, string $code): OAuth2LoginAuthentication
    {
        $id = $registration->registrationId;

        try {
            $response = $this->tokens->authorizationCode($registration, $code, $authorizationRequest->redirectUri, $authorizationRequest->codeVerifier);
        } catch (OAuth2AuthorizationException $e) {
            throw new OAuth2AuthenticationException($id, $e->error, $e);
        }
        $accessToken = $response->accessToken;

        if ($registration->usesOpenId()) {
            $idTokenValue = $response->additionalParameters['id_token'] ?? null;
            if (! is_string($idTokenValue) || $idTokenValue === '') {
                throw new OAuth2AuthenticationException($id, new OAuth2Error(OAuth2ErrorCodes::INVALID_ID_TOKEN, 'The token response carries no id_token.'));
            }
            $idToken = $this->decoders->createDecoder($registration)->decode($idTokenValue, $id);
            OidcIdTokenValidator::validate($idToken, $registration, $authorizationRequest->nonce);
            $user = $this->oidcUsers->loadUser(new OidcUserRequest($registration, $accessToken, $idToken, $response->additionalParameters));
        } else {
            $idTokenValue = null;
            $user = $this->oauth2Users->loadUser(new OAuth2UserRequest($registration, $accessToken, $response->additionalParameters));
        }

        $authorities = $this->authoritiesMapper?->mapAuthorities($user->getAuthorities()) ?? $user->getAuthorities();
        $authentication = OAuth2AuthenticationToken::of($user, $authorities, $id);
        $authorizedClient = new OAuth2AuthorizedClient($id, $user->getName(), $accessToken, $response->refreshToken, $idTokenValue);

        return new OAuth2LoginAuthentication($authentication, $user, $authorizedClient);
    }
}
