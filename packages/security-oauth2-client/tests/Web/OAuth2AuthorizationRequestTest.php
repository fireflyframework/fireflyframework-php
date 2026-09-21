<?php

declare(strict_types=1);

use Firefly\Security\OAuth2\Client\Registration\AuthorizationGrantType;
use Firefly\Security\OAuth2\Client\Registration\ClientAuthenticationMethod;
use Firefly\Security\OAuth2\Client\Registration\ClientRegistration;
use Firefly\Security\OAuth2\Client\Registration\ProviderDetails;
use Firefly\Security\OAuth2\Client\Web\OAuth2AuthorizationRequest;
use Firefly\Security\OAuth2\Client\Web\OAuth2AuthorizationRequestResolver;

it('builds the authorization URI with every RFC 6749 / OIDC / PKCE parameter, RFC 3986 encoded', function () {
    $request = new OAuth2AuthorizationRequest('okta', 'https://idp.example.com/authorize?tenant=a', 'app', 'https://app.example.com/login/oauth2/code/okta', ['openid', 'profile'], 'st', 'no', 'dBjftJeZ4CVP-mB92K27uhbUJU1p1r_wW1gFWFOEjXk', ['prompt' => 'consent']);

    expect($request->toUri())->toBe('https://idp.example.com/authorize?tenant=a&response_type=code&client_id=app&redirect_uri=https%3A%2F%2Fapp.example.com%2Flogin%2Foauth2%2Fcode%2Fokta&state=st&scope=openid%20profile&nonce=no&code_challenge=E9Melhoa2OwvFrEMTJguCHaoeK1t8URWbuGJSstw-cM&code_challenge_method=S256&prompt=consent')
        ->and(OAuth2AuthorizationRequest::codeChallenge('dBjftJeZ4CVP-mB92K27uhbUJU1p1r_wW1gFWFOEjXk'))->toBe('E9Melhoa2OwvFrEMTJguCHaoeK1t8URWbuGJSstw-cM')
        ->and((new OAuth2AuthorizationRequest('x', 'https://idp/a', 'c', 'https://app/cb', [], 'st'))->toUri())->toBe('https://idp/a?response_type=code&client_id=c&redirect_uri=https%3A%2F%2Fapp%2Fcb&state=st');
});

it('resolves a request with a fresh state, a nonce for openid, a PKCE verifier for pkce or a public client, and the expanded redirect uri', function () {
    $details = new ProviderDetails('https://idp.example.com/authorize', 'https://idp.example.com/token', 'https://idp.example.com/jwks');
    $oidc = new ClientRegistration('okta', 'app', 's', ClientAuthenticationMethod::ClientSecretBasic, AuthorizationGrantType::AuthorizationCode, '{baseUrl}/login/oauth2/code/{registrationId}', ['openid'], 'Okta', $details);
    $plain = new ClientRegistration('gh', 'app', 's', ClientAuthenticationMethod::ClientSecretBasic, AuthorizationGrantType::AuthorizationCode, '{baseUrl}/cb', ['read:user'], 'GitHub', $details, pkce: false);
    $public = new ClientRegistration('spa', 'app', '', ClientAuthenticationMethod::None, AuthorizationGrantType::AuthorizationCode, '{baseUrl}/cb', ['read:user'], 'SPA', $details, pkce: false);
    $resolver = new OAuth2AuthorizationRequestResolver;

    $a = $resolver->resolve($oidc, 'https://app.example.com/');
    $b = $resolver->resolve($oidc, 'https://app.example.com');
    $c = $resolver->resolve($plain, 'https://app.example.com');
    $d = $resolver->resolve($public, 'https://app.example.com');

    expect($a->registrationId)->toBe('okta')
        ->and($a->authorizationUri)->toBe('https://idp.example.com/authorize')
        ->and($a->redirectUri)->toBe('https://app.example.com/login/oauth2/code/okta')
        ->and($a->scopes)->toBe(['openid'])
        ->and(strlen($a->state))->toBe(43)
        ->and($a->state)->toMatch('/^[A-Za-z0-9_-]{43}$/')
        ->and($a->state)->not->toBe($b->state)
        ->and($a->nonce)->toMatch('/^[A-Za-z0-9_-]{43}$/')
        ->and($a->codeVerifier)->toMatch('/^[A-Za-z0-9_-]{43}$/')
        ->and($c->nonce)->toBeNull()
        ->and($c->codeVerifier)->toBeNull()
        ->and($d->codeVerifier)->not->toBeNull();
});
