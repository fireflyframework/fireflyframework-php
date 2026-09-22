<?php

declare(strict_types=1);

use Firefly\Security\OAuth2\Server\Client\InMemoryRegisteredClientRepository;
use Firefly\Security\OAuth2\Server\Client\RegisteredClientRepository;
use Firefly\Security\OAuth2\Server\Tests\Support\OAuth2ServerBootTestCase;

abstract class ClientsBootTestCase extends OAuth2ServerBootTestCase
{
    protected function serverOverrides(): array
    {
        return [
            'firefly.security.oauth2.server.clients' => [
                'driver' => 'memory',
                'web-app' => ['client_secret' => '{noop}web-secret', 'redirect_uris' => ['https://app.test/cb'], 'scopes' => ['openid']],
            ],
        ];
    }
}

uses(ClientsBootTestCase::class);

it('binds the memory repository from the clients map', function () {
    /** @var ClientsBootTestCase $this */
    $repository = $this->app()->make(RegisteredClientRepository::class);

    expect($repository)->toBeInstanceOf(InMemoryRegisteredClientRepository::class)
        ->and($repository->findByClientId('web-app')?->scopes)->toBe(['openid']);
});
