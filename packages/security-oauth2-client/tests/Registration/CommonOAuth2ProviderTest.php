<?php

declare(strict_types=1);

use Firefly\Security\OAuth2\Client\Registration\CommonOAuth2Provider;

it('ships Spring\'s Google and GitHub presets with every endpoint, so a registration needs only its credentials', function () {
    $google = CommonOAuth2Provider::Google;

    expect($google->provider())->toBe([
        'issuer_uri' => 'https://accounts.google.com',
        'authorization_uri' => 'https://accounts.google.com/o/oauth2/v2/auth',
        'token_uri' => 'https://www.googleapis.com/oauth2/v4/token',
        'jwk_set_uri' => 'https://www.googleapis.com/oauth2/v3/certs',
        'user_info_uri' => 'https://www.googleapis.com/oauth2/v3/userinfo',
        'user_name_attribute' => 'sub',
    ])->and($google->registration())->toBe(['scope' => ['openid', 'profile', 'email'], 'client_name' => 'Google', 'client_authentication_method' => 'client_secret_basic'])
        ->and($google->requiresIssuer())->toBeFalse()
        ->and(CommonOAuth2Provider::GitHub->provider()['user_name_attribute'])->toBe('id')
        ->and(CommonOAuth2Provider::GitHub->provider())->not->toHaveKey('issuer_uri')
        ->and(CommonOAuth2Provider::GitHub->registration()['scope'])->toBe(['read:user']);
});

it('treats okta, keycloak and microsoft (alias entra) as per-tenant presets that need an issuer', function () {
    expect(CommonOAuth2Provider::tryFromId('entra'))->toBe(CommonOAuth2Provider::Microsoft)
        ->and(CommonOAuth2Provider::tryFromId('Keycloak'))->toBe(CommonOAuth2Provider::Keycloak)
        ->and(CommonOAuth2Provider::tryFromId('nope'))->toBeNull()
        ->and(CommonOAuth2Provider::Okta->requiresIssuer())->toBeTrue()
        ->and(CommonOAuth2Provider::Keycloak->requiresIssuer())->toBeTrue()
        ->and(CommonOAuth2Provider::Microsoft->requiresIssuer())->toBeTrue()
        ->and(CommonOAuth2Provider::Keycloak->provider())->toBe(['user_name_attribute' => 'sub'])
        ->and(CommonOAuth2Provider::Microsoft->registration()['client_name'])->toBe('Microsoft');
});

it('presets the client authentication method only for the confidential-only providers, leaving the per-tenant ones to the registration', function () {
    // A Google or GitHub web OAuth app always has a secret; an Okta, Keycloak or Entra client may be public (PKCE only).
    expect(CommonOAuth2Provider::Google->registration()['client_authentication_method'])->toBe('client_secret_basic')
        ->and(CommonOAuth2Provider::GitHub->registration()['client_authentication_method'])->toBe('client_secret_basic')
        ->and(CommonOAuth2Provider::Okta->registration())->not->toHaveKey('client_authentication_method')
        ->and(CommonOAuth2Provider::Keycloak->registration())->not->toHaveKey('client_authentication_method')
        ->and(CommonOAuth2Provider::Microsoft->registration())->not->toHaveKey('client_authentication_method');
});
