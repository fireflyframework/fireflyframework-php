<?php

declare(strict_types=1);

namespace Firefly\Security\OAuth2\Client\User;

use Firefly\Security\OAuth2\Client\Token\OAuth2AuthenticationException;
use Firefly\Security\OAuth2\Client\Token\OAuth2Error;
use Firefly\Security\OAuth2\Client\Token\OAuth2ErrorCodes;

/**
 * The plain-OAuth2 user service (Spring's DefaultOAuth2UserService): the userinfo endpoint is the only source
 * of a principal, so a registration without one cannot sign anyone in (`missing_user_info_uri`), and the
 * response must carry `user_name_attribute` (`missing_user_name_attribute`). Authorities: OAUTH2_USER with the
 * attributes, then SCOPE_x per granted scope.
 */
final class DefaultOAuth2UserService implements OAuth2UserService
{
    public function __construct(private readonly UserInfoClient $userInfo) {}

    public function loadUser(OAuth2UserRequest $userRequest): OAuth2User
    {
        $registration = $userRequest->clientRegistration;
        $id = $registration->registrationId;

        $uri = $registration->providerDetails->userInfoUri;
        if ($uri === null || $uri === '') {
            throw new OAuth2AuthenticationException($id, new OAuth2Error(OAuth2ErrorCodes::MISSING_USER_INFO_URI, 'The provider has no userinfo endpoint and the registration does not request openid: there is no way to know who signed in.'));
        }

        $attributes = $this->userInfo->fetch($id, $uri, $userRequest->accessToken);
        $nameKey = $registration->providerDetails->userNameAttribute;
        if (! is_scalar($attributes[$nameKey] ?? null)) {
            throw new OAuth2AuthenticationException($id, new OAuth2Error(OAuth2ErrorCodes::MISSING_USER_NAME_ATTRIBUTE, "The userinfo response carries no [{$nameKey}] attribute (user_name_attribute) to name the principal by."));
        }

        return new DefaultOAuth2User(
            [new OAuth2UserAuthority(OAuth2UserAuthority::OAUTH2_USER, $attributes), ...OAuth2UserAuthority::scopes($userRequest->accessToken->scopes)],
            $attributes,
            $nameKey,
        );
    }
}
