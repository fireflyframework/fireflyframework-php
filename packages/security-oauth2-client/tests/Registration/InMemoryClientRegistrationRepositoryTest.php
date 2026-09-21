<?php

declare(strict_types=1);

use Firefly\Kernel\Exception\Framework\ConfigurationException;
use Firefly\Security\OAuth2\Client\Registration\AuthorizationGrantType;
use Firefly\Security\OAuth2\Client\Registration\ClientAuthenticationMethod;
use Firefly\Security\OAuth2\Client\Registration\ClientRegistration;
use Firefly\Security\OAuth2\Client\Registration\InMemoryClientRegistrationRepository;
use Firefly\Security\OAuth2\Client\Registration\ProviderDetails;

function memoryRegistration(string $id): ClientRegistration
{
    return new ClientRegistration($id, 'app', 's', ClientAuthenticationMethod::ClientSecretBasic, AuthorizationGrantType::AuthorizationCode, '{baseUrl}/cb', ['openid'], $id, new ProviderDetails('https://a', 'https://t', 'https://j'));
}

it('answers by id, lists its ids in order, and refuses a duplicate', function () {
    $repository = new InMemoryClientRegistrationRepository([memoryRegistration('google'), memoryRegistration('okta')]);

    expect($repository->findByRegistrationId('okta')?->clientName)->toBe('okta')
        ->and($repository->findByRegistrationId('nope'))->toBeNull()
        ->and($repository->registrationIds())->toBe(['google', 'okta'])
        ->and(array_map(static fn (ClientRegistration $r): string => $r->registrationId, $repository->all()))->toBe(['google', 'okta'])
        ->and(fn () => new InMemoryClientRegistrationRepository([memoryRegistration('a'), memoryRegistration('a')]))->toThrow(ConfigurationException::class, 'twice');
});
