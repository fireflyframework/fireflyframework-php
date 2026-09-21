<?php

declare(strict_types=1);

use Firefly\Security\Event\InteractiveAuthenticationSuccessEvent;
use Firefly\Security\OAuth2\Server\Tests\Support\OAuth2ServerBootTestCase;
use Firefly\Security\OAuth2\Server\Web\Consent\SessionAuthenticationTimeListener;
use Illuminate\Events\Dispatcher;

/** The same boot with the server off: OAuth2ServerEventListenersPass is a no-op and the gated bean is absent. */
abstract class ServerOffBootTestCase extends OAuth2ServerBootTestCase
{
    protected function serverOverrides(): array
    {
        return ['firefly.security.oauth2.server.enabled' => false];
    }
}

uses(ServerOffBootTestCase::class);

it('registers nothing while the server is off: no gated bean, no listener for the event', function () {
    /** @var ServerOffBootTestCase $this */
    /** @var Dispatcher $dispatcher */
    $dispatcher = $this->app()->make('events');

    expect($dispatcher->getRawListeners()[InteractiveAuthenticationSuccessEvent::class] ?? [])->toBe([])
        ->and($this->app()->bound(SessionAuthenticationTimeListener::class))->toBeFalse();
});
