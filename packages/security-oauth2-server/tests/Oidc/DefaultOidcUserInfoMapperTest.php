<?php

declare(strict_types=1);

use Firefly\Security\OAuth2\Server\Authorization\OAuth2Authorization;
use Firefly\Security\OAuth2\Server\Authorization\OAuth2Token;
use Firefly\Security\OAuth2\Server\Authorization\OAuth2TokenType;
use Firefly\Security\OAuth2\Server\Client\AuthorizationGrantType;
use Firefly\Security\OAuth2\Server\Client\RegisteredClientFactory;
use Firefly\Security\OAuth2\Server\Oidc\DefaultOidcUserInfoMapper;
use Firefly\Security\OAuth2\Server\Oidc\OidcUserInfoContext;
use Firefly\Security\OAuth2\Server\Settings\AuthorizationServerSettings;
use Firefly\Security\User\InMemoryUserDetailsService;

/**
 * @param  list<string>  $scopes
 */
function userInfoContext(string $principal, array $scopes): OidcUserInfoContext
{
    $client = RegisteredClientFactory::fromConfig('web-app', ['client_secret' => '{noop}s', 'redirect_uris' => ['https://a.test/cb']], new AuthorizationServerSettings);
    $authorization = OAuth2Authorization::create($client, $principal, AuthorizationGrantType::AuthorizationCode, $scopes);
    $token = OAuth2Token::issue(OAuth2TokenType::AccessToken, 'x', new DateTimeImmutable, null, ['scopes' => $scopes]);

    return new OidcUserInfoContext($authorization, $token, $scopes, ['sub' => $principal]);
}

it('answers sub always, the profile claims for profile, and email for email when the name is an address', function () {
    $mapper = new DefaultOidcUserInfoMapper;

    expect($mapper->map(userInfoContext('ada', ['openid'])))->toBe(['sub' => 'ada'])
        ->and($mapper->map(userInfoContext('ada', ['openid', 'profile'])))->toBe(['sub' => 'ada', 'name' => 'ada', 'preferred_username' => 'ada'])
        ->and($mapper->map(userInfoContext('ada', ['openid', 'email'])))->toBe(['sub' => 'ada'])
        ->and($mapper->map(userInfoContext('ada@example.com', ['openid', 'email'])))->toBe(['sub' => 'ada@example.com', 'email' => 'ada@example.com']);
});

it('drops the profile claims for a user the store no longer knows, keeping sub', function () {
    $users = InMemoryUserDetailsService::fromConfig(['ada' => ['password' => '{noop}x', 'authorities' => ['ROLE_USER']]]);
    $mapper = new DefaultOidcUserInfoMapper($users);

    expect($mapper->map(userInfoContext('ada', ['openid', 'profile'])))->toBe(['sub' => 'ada', 'name' => 'ada', 'preferred_username' => 'ada'])
        ->and($mapper->map(userInfoContext('gone', ['openid', 'profile'])))->toBe(['sub' => 'gone']);
});
