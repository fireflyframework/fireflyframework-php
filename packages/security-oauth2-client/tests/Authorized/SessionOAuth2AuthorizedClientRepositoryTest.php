<?php

declare(strict_types=1);

use Firefly\Security\OAuth2\Client\Authorized\OAuth2AuthorizedClient;
use Firefly\Security\OAuth2\Client\Authorized\SessionOAuth2AuthorizedClientRepository;
use Firefly\Security\OAuth2\Client\Token\OAuth2AccessToken;
use Firefly\Security\OAuth2\Client\Token\OAuth2RefreshToken;
use Illuminate\Container\Container;
use Illuminate\Contracts\Encryption\Encrypter as EncrypterContract;
use Illuminate\Encryption\Encrypter;
use Illuminate\Http\Request;
use Illuminate\Session\ArraySessionHandler;
use Illuminate\Session\Store;

function authorizedClientContainer(string $key = 'kkkkkkkkkkkkkkkkkkkkkkkkkkkkkkkk'): Container
{
    $container = new Container;
    $container->instance(EncrypterContract::class, new Encrypter($key, 'aes-256-cbc'));

    return $container;
}

function sessionRequest(): Request
{
    $request = Request::create('/home', 'GET');
    $store = new Store('firefly_session', new ArraySessionHandler(120));
    $store->start();
    $request->setLaravelSession($store);

    return $request;
}

function authorizedClient(string $registrationId = 'fake', string $principal = 'ada'): OAuth2AuthorizedClient
{
    return new OAuth2AuthorizedClient($registrationId, $principal, new OAuth2AccessToken('access-token-value', 100, 3700, ['openid']), new OAuth2RefreshToken('refresh-token-value', 100), 'raw.id.token');
}

it('stores one encrypted entry per registration in the session, reads it back, and never writes a token in clear', function () {
    $repository = new SessionOAuth2AuthorizedClientRepository(authorizedClientContainer());
    $request = sessionRequest();

    expect($repository->loadAuthorizedClient('fake', $request))->toBeNull();

    $repository->saveAuthorizedClient(authorizedClient(), $request);
    $repository->saveAuthorizedClient(authorizedClient('other', 'ada'), $request);

    $stored = $request->session()->get(SessionOAuth2AuthorizedClientRepository::KEY);
    $loaded = $repository->loadAuthorizedClient('fake', $request);

    expect($stored)->toBeArray()->toHaveKeys(['fake', 'other'])
        ->and(serialize($stored))->not->toContain('access-token-value')->not->toContain('refresh-token-value')->not->toContain('raw.id.token')
        ->and($loaded)->toEqual(authorizedClient())
        ->and($loaded?->accessToken->tokenValue)->toBe('access-token-value')
        ->and($loaded?->idToken)->toBe('raw.id.token')
        ->and($repository->loadAuthorizedClient('other', $request)?->registrationId)->toBe('other');

    $repository->removeAuthorizedClient('fake', $request);
    expect($repository->loadAuthorizedClient('fake', $request))->toBeNull()
        ->and($repository->loadAuthorizedClient('other', $request))->not->toBeNull();

    $repository->removeAuthorizedClient('other', $request);
    expect($request->session()->has(SessionOAuth2AuthorizedClientRepository::KEY))->toBeFalse();
});

it('drops an entry that no longer decrypts (a rotated application key) and is a no-op without a session', function () {
    $request = sessionRequest();
    (new SessionOAuth2AuthorizedClientRepository(authorizedClientContainer()))->saveAuthorizedClient(authorizedClient(), $request);

    $rotated = new SessionOAuth2AuthorizedClientRepository(authorizedClientContainer('rrrrrrrrrrrrrrrrrrrrrrrrrrrrrrrr'));

    expect($rotated->loadAuthorizedClient('fake', $request))->toBeNull()
        ->and($request->session()->has(SessionOAuth2AuthorizedClientRepository::KEY))->toBeFalse();

    $bare = Request::create('/home', 'GET');
    $repository = new SessionOAuth2AuthorizedClientRepository(authorizedClientContainer());
    $repository->saveAuthorizedClient(authorizedClient(), $bare);
    $repository->removeAuthorizedClient('fake', $bare);

    expect($repository->loadAuthorizedClient('fake', $bare))->toBeNull();
});
