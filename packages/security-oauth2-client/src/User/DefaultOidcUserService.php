<?php

declare(strict_types=1);

namespace Firefly\Security\OAuth2\Client\User;

use Firefly\Security\OAuth2\Client\Oidc\OidcUserInfo;
use Firefly\Security\OAuth2\Client\Token\OAuth2AuthenticationException;
use Firefly\Security\OAuth2\Client\Token\OAuth2Error;
use Firefly\Security\OAuth2\Client\Token\OAuth2ErrorCodes;

/**
 * The OpenID Connect user service (Spring's OidcUserService). The id token already names the user; the
 * userinfo endpoint is asked as well when the provider has one AND the access token was granted a scope that
 * makes it worth asking (`profile`, `email`, `address`, `phone` — Spring's rule), and its `sub` MUST equal the
 * id token's (OIDC Core §5.3.2), or the response is refused. Authorities: OIDC_USER carrying the claims
 * (value-less id token), then SCOPE_x per granted scope.
 */
final class DefaultOidcUserService implements OidcUserService
{
    private const array USER_INFO_SCOPES = ['profile', 'email', 'address', 'phone'];

    public function __construct(private readonly UserInfoClient $userInfo) {}

    public function loadUser(OidcUserRequest $userRequest): OidcUser
    {
        $registration = $userRequest->clientRegistration;
        $id = $registration->registrationId;
        $idToken = $userRequest->idToken;

        $userInfo = null;
        $uri = $registration->providerDetails->userInfoUri;
        if ($uri !== null && $uri !== '' && array_intersect($userRequest->accessToken->scopes, self::USER_INFO_SCOPES) !== []) {
            $claims = $this->userInfo->fetch($id, $uri, $userRequest->accessToken);
            $sub = $claims['sub'] ?? null;
            if (! is_scalar($sub) || (string) $sub !== $idToken->getSubject()) {
                throw new OAuth2AuthenticationException($id, new OAuth2Error(OAuth2ErrorCodes::INVALID_USER_INFO_RESPONSE, "The userinfo subject (sub) does not match the id token's (OpenID Connect Core §5.3.2)."));
            }
            $userInfo = new OidcUserInfo($claims);
        }

        $nameKey = $registration->providerDetails->userNameAttribute;
        $claims = [...$idToken->getClaims(), ...($userInfo?->getClaims() ?? [])];
        if (! is_scalar($claims[$nameKey] ?? null)) {
            throw new OAuth2AuthenticationException($id, new OAuth2Error(OAuth2ErrorCodes::MISSING_USER_NAME_ATTRIBUTE, "Neither the id token nor the userinfo carries a [{$nameKey}] claim (user_name_attribute) to name the principal by."));
        }

        return new DefaultOidcUser(
            [new OidcUserAuthority($idToken->withoutTokenValue(), $userInfo), ...OAuth2UserAuthority::scopes($userRequest->accessToken->scopes)],
            $idToken,
            $userInfo,
            $nameKey,
        );
    }
}
