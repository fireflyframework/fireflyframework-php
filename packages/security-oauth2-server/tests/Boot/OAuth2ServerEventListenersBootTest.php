<?php

declare(strict_types=1);

use Firefly\Security\Core\Authentication;
use Firefly\Security\Event\AuthenticationEventPublisher;
use Firefly\Security\Event\InteractiveAuthenticationSuccessEvent;
use Firefly\Security\OAuth2\Server\Tests\Support\OAuth2ServerBootTestCase;
use Firefly\Security\OAuth2\Server\Web\Consent\SessionAuthenticationTime;
use Firefly\Security\OAuth2\Server\Web\Consent\SessionAuthenticationTimeListener;
use Illuminate\Events\Dispatcher;
use Illuminate\Http\Request;
use Illuminate\Session\ArraySessionHandler;
use Illuminate\Session\Store;

/**
 * The listener registration of the package, at the boot level: the phase-800 sweep reads the application's
 * manifest only, so it is OAuth2ServerEventListenersPass that has to put SessionAuthenticationTimeListener on
 * the dispatcher. The server-off half is OAuth2ServerEventListenersOffBootTest.
 */
uses(OAuth2ServerBootTestCase::class);

it('registers the package\'s #[AsEventListener] on the dispatcher from the shipped manifest', function () {
    /** @var OAuth2ServerBootTestCase $this */
    /** @var Dispatcher $dispatcher */
    $dispatcher = $this->app()->make('events');

    // The raw listeners of the class itself: hasListeners() would also count firefly/cqrs's wildcard bridge.
    expect($dispatcher->getRawListeners()[InteractiveAuthenticationSuccessEvent::class] ?? [])->toHaveCount(1)
        ->and($this->app()->bound(SessionAuthenticationTimeListener::class))->toBeTrue();
});

it('stamps the current request\'s session when firefly/security reports an interactive sign-in', function () {
    /** @var OAuth2ServerBootTestCase $this */
    $store = new Store('firefly_session', new ArraySessionHandler(120));
    $store->start();
    $request = Request::create('/login', 'POST');
    $request->setLaravelSession($store);
    $this->app()->instance('request', $request);

    $before = time();
    $this->app()->make(AuthenticationEventPublisher::class)->publishInteractiveSuccess(Authentication::authenticated('ada', 'ada', []), InteractiveAuthenticationSuccessEvent::FORM);

    expect($store->get(SessionAuthenticationTime::KEY))->toBeInt()->toBeGreaterThanOrEqual($before);
});
