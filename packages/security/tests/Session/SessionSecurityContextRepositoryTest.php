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

    $stored = serialize($request->session()->get(SessionSecurityContextRepository::KEY));
    /** @var SecurityContext $loaded */
    $loaded = unserialize($stored);
    /** @var User $principal */
    $principal = $loaded->getAuthentication()?->getPrincipal();

    // What the session holds is the credential-free copy, not the token that was handed in: the principal is
    // the same user with its encoded password blanked, and the bytes a file driver writes never carry the hash.
    expect($repository->load($request))->not->toBe($context)
        ->and($repository->load($request)?->isAuthenticated())->toBeTrue()
        ->and($loaded->getAuthentication()?->getName())->toBe('ada')
        ->and($principal)->toEqual(new User('ada', '', [new SimpleGrantedAuthority('ROLE_USER')]))
        ->and($principal->getPassword())->toBe('')
        ->and($loaded->getAuthentication()?->authorityStrings())->toBe(['ROLE_USER'])
        ->and($stored)->not->toContain('{noop}x')
        ->and($user->getPassword())->toBe('{noop}x')
        ->and($context->getAuthentication()?->getPrincipal())->toBe($user);

    $repository->clear($request);

    expect($repository->load($request))->toBeNull();
});

it('stores a principal that is not a CredentialsContainer as it is', function () {
    $repository = new SessionSecurityContextRepository;
    $request = requestWithSession();
    $context = new SecurityContext(Authentication::authenticated('svc-1', 'svc-1', [new SimpleGrantedAuthority('SCOPE_orders:read')], ['iss' => 'issuer']));

    $repository->save($context, $request);

    $loaded = $repository->load($request);

    expect($loaded?->getAuthentication()?->getPrincipal())->toBe('svc-1')
        ->and($loaded?->getAuthentication()?->getAttributes())->toBe(['iss' => 'issuer'])
        ->and($loaded?->getAuthentication()?->authorityStrings())->toBe(['SCOPE_orders:read']);
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
