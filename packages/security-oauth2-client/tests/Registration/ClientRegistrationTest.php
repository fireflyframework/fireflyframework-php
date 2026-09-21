<?php

declare(strict_types=1);

use Firefly\Security\OAuth2\Client\Registration\AuthorizationGrantType;
use Firefly\Security\OAuth2\Client\Registration\ClientAuthenticationMethod;
use Firefly\Security\OAuth2\Client\Registration\ClientRegistration;
use Firefly\Security\OAuth2\Client\Registration\ProviderDetails;
use Firefly\Security\OAuth2\Client\Registration\RedirectUriTemplate;

/** @param list<string> $scopes */
function registration(array $scopes = ['openid', 'profile'], ClientAuthenticationMethod $method = ClientAuthenticationMethod::ClientSecretBasic, bool $pkce = true): ClientRegistration
{
    return new ClientRegistration(
        registrationId: 'okta',
        clientId: 'app',
        clientSecret: $method === ClientAuthenticationMethod::None ? '' : 'a-very-secret-value',
        clientAuthenticationMethod: $method,
        authorizationGrantType: AuthorizationGrantType::AuthorizationCode,
        redirectUri: '{baseUrl}/login/oauth2/code/{registrationId}',
        scopes: $scopes,
        clientName: 'Okta',
        providerDetails: new ProviderDetails('https://idp.example.com/authorize', 'https://idp.example.com/token', 'https://idp.example.com/jwks', null, 'sub', 'https://idp.example.com', 'https://idp.example.com/logout'),
        pkce: $pkce,
    );
}

it('knows whether it is an OpenID registration, a public client, and whether PKCE is used', function () {
    expect(registration()->usesOpenId())->toBeTrue()
        ->and(registration(['read:user'])->usesOpenId())->toBeFalse()
        ->and(registration()->isPublicClient())->toBeFalse()
        ->and(registration(method: ClientAuthenticationMethod::None)->isPublicClient())->toBeTrue()
        ->and(registration(pkce: false)->usesPkce())->toBeFalse()
        // A public client has no secret to prove itself with: PKCE is not optional for it, whatever `pkce` says.
        ->and(registration(method: ClientAuthenticationMethod::None, pkce: false)->usesPkce())->toBeTrue();
});

it('never prints its client secret through a dump', function () {
    $dump = print_r(registration(), true);
    $debug = registration()->__debugInfo();

    expect($dump)->not->toContain('a-very-secret-value')
        ->and($debug['clientSecret'])->toBe('***')
        ->and($debug['clientId'])->toBe('app')
        ->and(registration(method: ClientAuthenticationMethod::None)->__debugInfo()['clientSecret'])->toBe('');
});

it('expands Spring\'s redirect-uri template from the application\'s base URL', function () {
    expect(RedirectUriTemplate::expand('{baseUrl}/login/oauth2/code/{registrationId}', 'https://app.example.com', 'okta'))->toBe('https://app.example.com/login/oauth2/code/okta')
        ->and(RedirectUriTemplate::expand('{baseUrl}/login/oauth2/code/{registrationId}', 'https://app.example.com/', 'okta'))->toBe('https://app.example.com/login/oauth2/code/okta')
        ->and(RedirectUriTemplate::expand('https://fixed.example.com/cb', 'https://app.example.com', 'okta'))->toBe('https://fixed.example.com/cb');
});
