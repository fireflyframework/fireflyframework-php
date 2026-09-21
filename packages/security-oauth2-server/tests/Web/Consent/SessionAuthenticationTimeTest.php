<?php

declare(strict_types=1);

use Firefly\Security\Core\Authentication;
use Firefly\Security\Event\InteractiveAuthenticationSuccessEvent;
use Firefly\Security\OAuth2\Server\Web\Consent\SessionAuthenticationTime;
use Firefly\Security\OAuth2\Server\Web\Consent\SessionAuthenticationTimeListener;
use Illuminate\Container\Container;
use Illuminate\Http\Request;
use Illuminate\Session\ArraySessionHandler;
use Illuminate\Session\Store;

function startedSessionStore(): Store
{
    $store = new Store('firefly_session', new ArraySessionHandler(120));
    $store->start();

    return $store;
}

function interactiveSignIn(): InteractiveAuthenticationSuccessEvent
{
    return new InteractiveAuthenticationSuccessEvent(Authentication::authenticated('ada', 'ada', []), InteractiveAuthenticationSuccessEvent::FORM);
}

it('keeps the stamp once written, and stamps now only for a session that has none', function () {
    $session = startedSessionStore();

    $before = time();
    $first = SessionAuthenticationTime::of($session);
    expect($first)->toBeGreaterThanOrEqual($before)
        ->and($session->get(SessionAuthenticationTime::KEY))->toBe($first);

    $session->put(SessionAuthenticationTime::KEY, 1_700_000_000);
    expect(SessionAuthenticationTime::of($session))->toBe(1_700_000_000);

    SessionAuthenticationTime::stamp($session);
    expect(SessionAuthenticationTime::of($session))->toBeGreaterThanOrEqual($before);

    SessionAuthenticationTime::forget($session);
    expect($session->get(SessionAuthenticationTime::KEY))->toBeNull();
});

it('stamps the session of the request the container holds at the moment of the sign-in, never a captured one', function () {
    // Request::class is an alias of the `request` instance, exactly as Laravel's Application registers it.
    $container = new Container;
    $container->alias('request', Request::class);
    $listener = new SessionAuthenticationTimeListener($container);

    $first = startedSessionStore();
    $request = Request::create('/login', 'POST');
    $request->setLaravelSession($first);
    $container->instance('request', $request);

    $before = time();
    $listener->onInteractiveAuthenticationSuccess(interactiveSignIn());
    expect($first->get(SessionAuthenticationTime::KEY))->toBeInt()->toBeGreaterThanOrEqual($before);

    // The next request is another object with another session: that one is stamped, the first is left alone.
    $second = startedSessionStore();
    $next = Request::create('/login', 'POST');
    $next->setLaravelSession($second);
    $container->instance('request', $next);
    $first->put(SessionAuthenticationTime::KEY, 1_700_000_000);

    $listener->onInteractiveAuthenticationSuccess(interactiveSignIn());
    expect($second->get(SessionAuthenticationTime::KEY))->toBeInt()->toBeGreaterThanOrEqual($before)
        ->and($first->get(SessionAuthenticationTime::KEY))->toBe(1_700_000_000);
});

it('does nothing in a container that holds no request, or for a request without a session', function () {
    $container = new Container;
    $listener = new SessionAuthenticationTimeListener($container);

    // Nothing bound and nothing aliased: no request is built out of thin air just to find it has no session.
    $listener->onInteractiveAuthenticationSuccess(interactiveSignIn());
    expect($container->resolved(Request::class))->toBeFalse();

    // A request with no session (a stateless API path): resolved, left alone, no session conjured to stamp.
    $stateless = Request::create('/api/orders', 'GET');
    $container->alias('request', Request::class);
    $container->instance('request', $stateless);
    $listener->onInteractiveAuthenticationSuccess(interactiveSignIn());
    expect($stateless->hasSession())->toBeFalse();
});
