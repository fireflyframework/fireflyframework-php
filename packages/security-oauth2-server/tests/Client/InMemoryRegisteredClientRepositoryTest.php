<?php

declare(strict_types=1);

use Firefly\Kernel\Exception\Framework\ConfigurationException;
use Firefly\Security\OAuth2\Server\Client\InMemoryRegisteredClientRepository;
use Firefly\Security\OAuth2\Server\Client\RegisteredClientFactory;
use Firefly\Security\OAuth2\Server\Settings\AuthorizationServerSettings;

it('builds from the config map, skipping the reserved driver key, and answers by id and by client_id', function () {
    $repository = InMemoryRegisteredClientRepository::fromConfig([
        'driver' => 'memory',
        'web-app' => ['client_secret' => '{noop}s', 'redirect_uris' => ['https://a.test/cb']],
        'svc' => ['client_id' => 'service-account', 'client_secret' => '{noop}s', 'authorization_grant_types' => ['client_credentials']],
    ], new AuthorizationServerSettings);

    expect($repository->all())->toHaveCount(2)
        ->and($repository->findByClientId('service-account')?->id)->toBe('svc')
        ->and($repository->findById('svc')?->clientId)->toBe('service-account')
        ->and($repository->findByClientId('nope'))->toBeNull()
        ->and($repository->findById('driver'))->toBeNull();
});

it('refuses two blocks that share a client_id', function () {
    expect(fn () => InMemoryRegisteredClientRepository::fromConfig([
        'a' => ['client_id' => 'same', 'client_secret' => '{noop}s', 'authorization_grant_types' => ['client_credentials']],
        'b' => ['client_id' => 'same', 'client_secret' => '{noop}s', 'authorization_grant_types' => ['client_credentials']],
    ], new AuthorizationServerSettings))->toThrow(ConfigurationException::class, 'same');
});

it('saves a client (a registration, a test) and replaces one with the same id', function () {
    $repository = new InMemoryRegisteredClientRepository;
    $settings = new AuthorizationServerSettings;
    $repository->save(RegisteredClientFactory::fromConfig('dyn', ['client_secret' => '{noop}s', 'redirect_uris' => ['https://a.test/cb']], $settings));
    $repository->save(RegisteredClientFactory::fromConfig('dyn', ['client_secret' => '{noop}s', 'redirect_uris' => ['https://b.test/cb']], $settings));

    expect($repository->all())->toHaveCount(1)
        ->and($repository->findByClientId('dyn')?->redirectUris)->toBe(['https://b.test/cb']);
});
