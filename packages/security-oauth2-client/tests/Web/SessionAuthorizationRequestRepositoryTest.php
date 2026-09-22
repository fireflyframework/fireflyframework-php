<?php

declare(strict_types=1);

use Firefly\Security\OAuth2\Client\Web\OAuth2AuthorizationRequest;
use Firefly\Security\OAuth2\Client\Web\SessionAuthorizationRequestRepository;
use Illuminate\Http\Request;
use Illuminate\Session\ArraySessionHandler;
use Illuminate\Session\Store;

function authorizationCallbackWithSession(): Request
{
    $request = Request::create('/login/oauth2/code/okta', 'GET');
    $store = new Store('firefly_session', new ArraySessionHandler(120));
    $store->start();
    $request->setLaravelSession($store);

    return $request;
}

it('keeps one authorization request per session, hands it back once, and is a no-op without a session', function () {
    $repository = new SessionAuthorizationRequestRepository;
    $request = authorizationCallbackWithSession();
    $authorization = new OAuth2AuthorizationRequest('okta', 'https://idp/a', 'c', 'https://app/cb', ['openid'], 'st', 'no', 'cv');

    expect($repository->loadAuthorizationRequest($request))->toBeNull();

    $repository->saveAuthorizationRequest($authorization, $request);

    expect($repository->loadAuthorizationRequest($request))->toEqual($authorization)
        ->and($request->session()->get(SessionAuthorizationRequestRepository::KEY))->toEqual($authorization)
        ->and($repository->removeAuthorizationRequest($request))->toEqual($authorization)
        ->and($repository->removeAuthorizationRequest($request))->toBeNull()
        ->and($repository->loadAuthorizationRequest($request))->toBeNull();

    $bare = Request::create('/login/oauth2/code/okta', 'GET');
    $repository->saveAuthorizationRequest($authorization, $bare);
    expect($repository->loadAuthorizationRequest($bare))->toBeNull()
        ->and($repository->removeAuthorizationRequest($bare))->toBeNull();
});
