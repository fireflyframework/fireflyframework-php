<?php

declare(strict_types=1);

use Firefly\Security\OAuth2\Client\Oidc\OidcIdToken;
use Firefly\Security\OAuth2\Client\Oidc\OidcIdTokenValidator;
use Firefly\Security\OAuth2\Client\Registration\AuthorizationGrantType;
use Firefly\Security\OAuth2\Client\Registration\ClientAuthenticationMethod;
use Firefly\Security\OAuth2\Client\Registration\ClientRegistration;
use Firefly\Security\OAuth2\Client\Registration\ProviderDetails;
use Firefly\Security\OAuth2\Client\Token\OAuth2AuthenticationException;

function validatorRegistration(?string $issuer = 'https://idp.example.test/'): ClientRegistration
{
    return new ClientRegistration('fake', 'app', 's', ClientAuthenticationMethod::ClientSecretBasic, AuthorizationGrantType::AuthorizationCode, '{baseUrl}/cb', ['openid'], 'Fake', new ProviderDetails('https://a', 'https://t', 'https://j', null, 'sub', $issuer));
}

/** @param array<string, mixed> $overrides */
function idToken(array $overrides = []): OidcIdToken
{
    return new OidcIdToken('raw', array_filter(array_replace(['iss' => 'https://idp.example.test', 'sub' => 'ada', 'aud' => 'app', 'iat' => 1, 'exp' => 2, 'nonce' => 'n-1'], $overrides), static fn (mixed $v): bool => $v !== null));
}

function refusal(callable $attempt): string
{
    try {
        $attempt();
    } catch (OAuth2AuthenticationException $e) {
        return $e->error->errorCode;
    }

    return 'accepted';
}

it('accepts a token whose iss, aud, azp, iat, sub and nonce are right, with a trailing slash on the issuer tolerated', function () {
    OidcIdTokenValidator::validate(idToken(), validatorRegistration(), 'n-1');
    OidcIdTokenValidator::validate(idToken(['aud' => ['app', 'other'], 'azp' => 'app']), validatorRegistration(), 'n-1');
    OidcIdTokenValidator::validate(idToken(['iss' => 'https://elsewhere']), validatorRegistration(null), 'n-1');

    expect(true)->toBeTrue();
});

it('refuses every claim OIDC Core §3.1.3.7 says to check', function () {
    expect(refusal(fn () => OidcIdTokenValidator::validate(idToken(['iss' => 'https://evil.example.test']), validatorRegistration(), 'n-1')))->toBe('invalid_id_token')
        ->and(refusal(fn () => OidcIdTokenValidator::validate(idToken(['aud' => 'other']), validatorRegistration(), 'n-1')))->toBe('invalid_id_token')
        ->and(refusal(fn () => OidcIdTokenValidator::validate(idToken(['aud' => ['app', 'other']]), validatorRegistration(), 'n-1')))->toBe('invalid_id_token')
        ->and(refusal(fn () => OidcIdTokenValidator::validate(idToken(['azp' => 'other']), validatorRegistration(), 'n-1')))->toBe('invalid_id_token')
        ->and(refusal(fn () => OidcIdTokenValidator::validate(idToken(['iat' => null]), validatorRegistration(), 'n-1')))->toBe('invalid_id_token')
        ->and(refusal(fn () => OidcIdTokenValidator::validate(idToken(['sub' => null]), validatorRegistration(), 'n-1')))->toBe('invalid_id_token')
        ->and(refusal(fn () => OidcIdTokenValidator::validate(idToken(['nonce' => 'wrong']), validatorRegistration(), 'n-1')))->toBe('invalid_nonce')
        ->and(refusal(fn () => OidcIdTokenValidator::validate(idToken(['nonce' => null]), validatorRegistration(), 'n-1')))->toBe('invalid_nonce')
        ->and(refusal(fn () => OidcIdTokenValidator::validate(idToken(['nonce' => null]), validatorRegistration(), null)))->toBe('accepted');
});
