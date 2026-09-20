<?php

declare(strict_types=1);

use Firefly\Security\Core\Authentication;
use Firefly\Security\Core\SecurityContext;
use Firefly\Security\Core\SimpleGrantedAuthority;
use Firefly\Security\Session\SavedRequest;
use Firefly\Security\Session\SessionSecurityContextRepository;
use Firefly\Security\User\User;
use Illuminate\Http\Request;
use Illuminate\Session\ArraySessionHandler;
use Illuminate\Session\Store;

function requestWithSession(): Request
{
    $request = Request::create('/x', 'GET');
    $store = new Store('firefly_session', new ArraySessionHandler(120));
    $store->start();
    $request->setLaravelSession($store);

    return $request;
}

it('saves, loads and clears a context, and survives the serialisation a file session applies', function () {
    $repository = new SessionSecurityContextRepository;
    $request = requestWithSession();
    $user = new User('ada', '{noop}x', [new SimpleGrantedAuthority('ROLE_USER')]);
    $context = new SecurityContext(Authentication::authenticated('ada', $user, $user->getAuthorities()));

    expect($repository->load($request))->toBeNull();

    $repository->save($context, $request);

    /** @var SecurityContext $loaded */
    $loaded = unserialize(serialize($request->session()->get(SessionSecurityContextRepository::KEY)));

    expect($repository->load($request))->toBe($context)
        ->and($loaded->getAuthentication()?->getName())->toBe('ada')
        ->and($loaded->getAuthentication()?->getPrincipal())->toEqual($user)
        ->and($loaded->getAuthentication()?->authorityStrings())->toBe(['ROLE_USER']);

    $repository->clear($request);

    expect($repository->load($request))->toBeNull();
});

it('ignores a request without a session and an anonymous stored context', function () {
    $repository = new SessionSecurityContextRepository;

    expect($repository->load(Request::create('/x', 'GET')))->toBeNull();

    $request = requestWithSession();
    $request->session()->put(SessionSecurityContextRepository::KEY, SecurityContext::anonymous());

    expect($repository->load($request))->toBeNull();
});

it('stores a GET request to come back to, and consumes it once', function () {
    $request = Request::create('http://localhost/reports?year=2026', 'GET');
    $session = requestWithSession()->session();

    SavedRequest::store($session, $request);

    expect(SavedRequest::consume($session))->toBe('http://localhost/reports?year=2026')
        ->and(SavedRequest::consume($session))->toBeNull();
});
