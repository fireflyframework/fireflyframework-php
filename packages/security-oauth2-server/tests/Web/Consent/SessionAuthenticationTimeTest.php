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

function interactiveSignIn(string $mechanism = InteractiveAuthenticationSuccessEvent::FORM): InteractiveAuthenticationSuccessEvent
{
    return new InteractiveAuthenticationSuccessEvent(Authentication::authenticated('ada', 'ada', []), $mechanism);
}

it('keeps the stamp once written, and stamps now only for a session-held principal that has none', function () {
    $session = startedSessionStore();

    $before = time();
    $first = SessionAuthenticationTime::ofSessionHeldPrincipal($session);
    expect($first)->toBeGreaterThanOrEqual($before)
        ->and($session->get(SessionAuthenticationTime::KEY))->toBe($first);

    $session->put(SessionAuthenticationTime::KEY, 1_700_000_000);
    expect(SessionAuthenticationTime::ofSessionHeldPrincipal($session))->toBe(1_700_000_000);

    SessionAuthenticationTime::stamp($session);
    expect(SessionAuthenticationTime::ofSessionHeldPrincipal($session))->toBeGreaterThanOrEqual($before);

    SessionAuthenticationTime::forget($session);
    expect($session->get(SessionAuthenticationTime::KEY))->toBeNull();
});

it('marks a session the cookie signed in as remembered — no active instant, so the reader answers null and never stamps now', function () {
    $session = startedSessionStore();

    SessionAuthenticationTime::remembered($session);
    expect($session->get(SessionAuthenticationTime::KEY))->toBe(SessionAuthenticationTime::REMEMBERED)
        ->and(SessionAuthenticationTime::ofSessionHeldPrincipal($session))->toBeNull()
        ->and($session->get(SessionAuthenticationTime::KEY))->toBe(SessionAuthenticationTime::REMEMBERED);

    // A credentialed sign-in after it is an active one: the instant replaces the marker.
    SessionAuthenticationTime::stamp($session);
    expect(SessionAuthenticationTime::ofSessionHeldPrincipal($session))->toBeInt();

    // The other way round, an active instant already there is the last active authentication: the cookie keeps it.
    $session->put(SessionAuthenticationTime::KEY, 1_700_000_000);
    SessionAuthenticationTime::remembered($session);
    expect(SessionAuthenticationTime::ofSessionHeldPrincipal($session))->toBe(1_700_000_000);

    // forget() clears the marker like the instant, so the next look falls back to stamping now.
    SessionAuthenticationTime::forget($session);
    SessionAuthenticationTime::remembered($session);
    SessionAuthenticationTime::forget($session);
    expect(SessionAuthenticationTime::ofSessionHeldPrincipal($session))->toBeInt();
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

it('stamps the form and Basic as active sign-ins, and marks a remember-me sign-in as remembered without touching an instant already there', function () {
    $container = new Container;
    $container->alias('request', Request::class);
    $listener = new SessionAuthenticationTimeListener($container);

    // The cookie alone: remembered, not an instant.
    $remembered = startedSessionStore();
    $request = Request::create('/oauth2/authorize', 'GET');
    $request->setLaravelSession($remembered);
    $container->instance('request', $request);
    $listener->onInteractiveAuthenticationSuccess(interactiveSignIn(InteractiveAuthenticationSuccessEvent::REMEMBER_ME));
    expect($remembered->get(SessionAuthenticationTime::KEY))->toBe(SessionAuthenticationTime::REMEMBERED);

    // Basic, like the form, is a credential presented now.
    $listener->onInteractiveAuthenticationSuccess(interactiveSignIn(InteractiveAuthenticationSuccessEvent::BASIC));
    expect($remembered->get(SessionAuthenticationTime::KEY))->toBeInt();

    // A cookie sign-in in a session that already holds an active instant leaves that instant alone.
    $remembered->put(SessionAuthenticationTime::KEY, 1_700_000_000);
    $listener->onInteractiveAuthenticationSuccess(interactiveSignIn(InteractiveAuthenticationSuccessEvent::REMEMBER_ME));
    expect($remembered->get(SessionAuthenticationTime::KEY))->toBe(1_700_000_000);
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
