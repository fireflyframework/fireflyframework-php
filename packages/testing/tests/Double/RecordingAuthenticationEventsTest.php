<?php

declare(strict_types=1);

use Firefly\Security\Authentication\Exception\BadCredentialsException;
use Firefly\Security\Core\Authentication;
use Firefly\Security\Event\AuthenticationEventPublisher;
use Firefly\Security\Event\AuthenticationFailureBadCredentialsEvent;
use Firefly\Security\Event\InteractiveAuthenticationSuccessEvent;
use Firefly\Testing\Double\RecordingAuthenticationEvents;

it('records every published event and answers the security ones by kind', function () {
    $recorder = new RecordingAuthenticationEvents;
    $publisher = new AuthenticationEventPublisher($recorder);
    $ada = Authentication::authenticated('ada', 'ada', []);

    $publisher->publishInteractiveSuccess($ada, InteractiveAuthenticationSuccessEvent::BASIC);
    $publisher->publishAuthenticationFailure(new BadCredentialsException('Bad credentials.'), 'bob', '127.0.0.1');
    $publisher->publishLogoutSuccess($ada);
    $publisher->publishAuthorizationDenied($ada, 'GET /admin', 'denyAll()');
    $recorder->publish(new stdClass);

    expect($recorder->events)->toHaveCount(6)
        ->and($recorder->successes())->toHaveCount(1)
        ->and($recorder->interactive())->toHaveCount(1)
        ->and($recorder->interactive()[0]->mechanism)->toBe('basic')
        ->and($recorder->failures())->toHaveCount(1)
        ->and($recorder->failures()[0])->toBeInstanceOf(AuthenticationFailureBadCredentialsEvent::class)
        ->and($recorder->logouts())->toHaveCount(1)
        ->and($recorder->denials())->toHaveCount(1)
        ->and($recorder->ofType(stdClass::class))->toHaveCount(1);

    $recorder->reset();

    expect($recorder->events)->toBe([]);
});
