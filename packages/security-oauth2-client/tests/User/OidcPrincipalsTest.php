<?php

declare(strict_types=1);

use Firefly\Security\Core\Authentication;
use Firefly\Security\Core\SimpleGrantedAuthority;
use Firefly\Security\OAuth2\Client\Oidc\OidcIdToken;
use Firefly\Security\OAuth2\Client\Oidc\OidcUserInfo;
use Firefly\Security\OAuth2\Client\User\DefaultOAuth2User;
use Firefly\Security\OAuth2\Client\User\DefaultOidcUser;
use Firefly\Security\OAuth2\Client\User\OAuth2AuthenticationToken;
use Firefly\Security\OAuth2\Client\User\OAuth2UserAuthority;
use Firefly\Security\OAuth2\Client\User\OidcUserAuthority;

it('merges userinfo over the id token, names itself by the attribute, and stores itself without the raw token', function () {
    $idToken = new OidcIdToken('raw.id.token', ['iss' => 'https://idp', 'sub' => 'u-1', 'aud' => 'app', 'exp' => 2, 'iat' => 1, 'name' => 'Ada', 'email' => 'old@example.com']);
    $userInfo = new OidcUserInfo(['sub' => 'u-1', 'email' => 'ada@example.com', 'preferred_username' => 'ada', 'groups' => ['eng']]);
    $user = new DefaultOidcUser([new OidcUserAuthority($idToken->withoutTokenValue(), $userInfo), ...OAuth2UserAuthority::scopes(['openid', 'email'])], $idToken, $userInfo, 'preferred_username');

    expect($user->getName())->toBe('ada')
        ->and($user->getSubject())->toBe('u-1')
        ->and($user->getEmail())->toBe('ada@example.com')
        ->and($user->getFullName())->toBe('Ada')
        ->and($user->getPreferredUsername())->toBe('ada')
        ->and($user->getClaim('groups'))->toBe(['eng'])
        ->and($user->getAttribute('iss'))->toBe('https://idp')
        ->and($user->getAttributes())->toBe($user->getClaims())
        ->and($user->getIdToken()->getTokenValue())->toBe('raw.id.token')
        ->and($user->getUserInfo()?->getPreferredUsername())->toBe('ada')
        ->and(array_map(static fn ($a): string => $a->getAuthority(), $user->getAuthorities()))->toBe(['OIDC_USER', 'SCOPE_openid', 'SCOPE_email']);

    $stored = $user->eraseCredentials();
    expect($stored->getIdToken()->getTokenValue())->toBe('')
        ->and($stored->getIdToken()->getClaims())->toBe($idToken->getClaims())
        ->and($stored->getName())->toBe('ada')
        ->and(serialize($stored))->not->toContain('raw.id.token')
        ->and(serialize($user->getAuthorities()))->not->toContain('raw.id.token');

    $authority = $user->getAuthorities()[0];
    expect($authority)->toBeInstanceOf(OidcUserAuthority::class)
        ->and($authority instanceof OidcUserAuthority ? $authority->getUserInfo()?->getEmail() : null)->toBe('ada@example.com')
        ->and($authority instanceof OidcUserAuthority ? $authority->getAttributes()['name'] : null)->toBe('Ada');
});

it('refuses a principal that cannot be named, and names a plain OAuth2 user by its attribute', function () {
    expect(fn () => new DefaultOidcUser([], new OidcIdToken('r', ['sub' => 'u-1']), null, 'preferred_username'))->toThrow(InvalidArgumentException::class, 'preferred_username')
        ->and(fn () => new DefaultOAuth2User([], ['login' => 'octocat'], 'id'))->toThrow(InvalidArgumentException::class, 'id');

    $user = new DefaultOAuth2User([new OAuth2UserAuthority(OAuth2UserAuthority::OAUTH2_USER, ['id' => 583231]), ...OAuth2UserAuthority::scopes(['read:user'])], ['id' => 583231, 'login' => 'octocat'], 'id');

    expect($user->getName())->toBe('583231')
        ->and($user->getAttribute('login'))->toBe('octocat')
        ->and(array_map(static fn ($a): string => $a->getAuthority(), $user->getAuthorities()))->toBe(['OAUTH2_USER', 'SCOPE_read:user']);
});

it('builds the authentication token with the registration id as an attribute, and reads it back', function () {
    $user = new DefaultOAuth2User([new SimpleGrantedAuthority('OAUTH2_USER')], ['id' => 7], 'id');

    $token = OAuth2AuthenticationToken::of($user, [new SimpleGrantedAuthority('ROLE_USER')], 'github');

    expect($token->getName())->toBe('7')
        ->and($token->getPrincipal())->toBe($user)
        ->and($token->authorityStrings())->toBe(['ROLE_USER'])
        ->and($token->isAuthenticated())->toBeTrue()
        ->and(OAuth2AuthenticationToken::registrationId($token))->toBe('github')
        ->and(OAuth2AuthenticationToken::principal($token))->toBe($user)
        ->and(OAuth2AuthenticationToken::registrationId(Authentication::authenticated('ada', 'ada', [])))->toBeNull()
        ->and(OAuth2AuthenticationToken::principal(null))->toBeNull();
});
