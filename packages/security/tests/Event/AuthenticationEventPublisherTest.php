<?php

declare(strict_types=1);

use Firefly\Kernel\Exception\Security\AuthenticationException;
use Firefly\Security\Authentication\Exception\BadCredentialsException;
use Firefly\Security\Authentication\Exception\DisabledException;
use Firefly\Security\Authentication\Exception\LockedException;
use Firefly\Security\Authentication\Exception\UsernameNotFoundException;
use Firefly\Security\Core\Authentication;
use Firefly\Security\Core\SimpleGrantedAuthority;
use Firefly\Security\Event\AbstractAuthenticationFailureEvent;
use Firefly\Security\Event\AuthenticationEventPublisher;
use Firefly\Security\Event\AuthenticationFailureBadCredentialsEvent;
use Firefly\Security\Event\AuthenticationFailureDisabledEvent;
use Firefly\Security\Event\AuthenticationFailureLockedEvent;
use Firefly\Security\Event\AuthenticationSuccessEvent;
use Firefly\Security\Event\AuthorizationDeniedEvent;
use Firefly\Security\Event\InteractiveAuthenticationSuccessEvent;
use Firefly\Security\Event\LogoutSuccessEvent;
use Firefly\Testing\Double\RecordingApplicationEventPublisher;

function ada(): Authentication
{
    return Authentication::authenticated('ada', 'ada', [new SimpleGrantedAuthority('ROLE_USER')]);
}

/**
 * Narrow a recorded `object` to the concrete event class before reading its properties — the same
 * instanceof-narrow-or-throw shape as AfterCommitDispatchTest's asItemAdded (no @var override, no cast).
 *
 * @template T of object
 *
 * @param  class-string<T>  $class
 * @return T
 */
function securityEventAs(object $event, string $class): object
{
    if (! $event instanceof $class) {
        throw new RuntimeException("Expected a {$class} event, got ".$event::class.'.');
    }

    return $event;
}

it('publishes a success, and an interactive success as BOTH the generic and the interactive event', function () {
    $sink = new RecordingApplicationEventPublisher;
    $publisher = new AuthenticationEventPublisher($sink);

    $publisher->publishAuthenticationSuccess(ada());
    $publisher->publishInteractiveSuccess(ada(), InteractiveAuthenticationSuccessEvent::FORM);

    expect($sink->events)->toHaveCount(3)
        ->and($sink->events[0])->toBeInstanceOf(AuthenticationSuccessEvent::class)
        ->and($sink->events[1])->toBeInstanceOf(AuthenticationSuccessEvent::class)
        ->and($sink->events[2])->toBeInstanceOf(InteractiveAuthenticationSuccessEvent::class);

    $interactive = securityEventAs($sink->events[2], InteractiveAuthenticationSuccessEvent::class);

    expect($interactive->mechanism)->toBe('form')
        ->and($interactive->authentication->getName())->toBe('ada');
});

it('maps each failure to its event, carrying the username and the source ip and never a password', function (AuthenticationException $exception, string $event) {
    $sink = new RecordingApplicationEventPublisher;

    (new AuthenticationEventPublisher($sink))->publishAuthenticationFailure($exception, 'ada', '203.0.113.7');

    expect($sink->events)->toHaveCount(1);

    $failure = securityEventAs($sink->events[0], AbstractAuthenticationFailureEvent::class);

    expect($failure::class)->toBe($event)
        ->and($failure->username)->toBe('ada')
        ->and($failure->ip)->toBe('203.0.113.7')
        ->and($failure->exception)->toBe($exception)
        ->and(get_object_vars($failure))->not->toHaveKey('password');
})->with([
    'bad credentials' => [new BadCredentialsException('Bad credentials.'), AuthenticationFailureBadCredentialsEvent::class],
    'locked' => [new LockedException('Account is locked.'), AuthenticationFailureLockedEvent::class],
    'disabled' => [new DisabledException('Account is disabled.'), AuthenticationFailureDisabledEvent::class],
    // An unknown user is reported as BAD CREDENTIALS on purpose: a distinct event would let a listener (a log
    // line, a metric) tell an observer which usernames exist.
    'unknown user' => [new UsernameNotFoundException('No user found for username [ada].'), AuthenticationFailureBadCredentialsEvent::class],
]);

it('publishes a logout and a denial', function () {
    $sink = new RecordingApplicationEventPublisher;
    $publisher = new AuthenticationEventPublisher($sink);

    $publisher->publishLogoutSuccess(ada());
    $publisher->publishLogoutSuccess(null);
    $publisher->publishAuthorizationDenied(ada(), 'App\\Reports::totals', "hasRole('ADMIN')");

    $logout = securityEventAs($sink->events[0], LogoutSuccessEvent::class);
    $anonymousLogout = securityEventAs($sink->events[1], LogoutSuccessEvent::class);
    $denial = securityEventAs($sink->events[2], AuthorizationDeniedEvent::class);

    expect($logout->authentication?->getName())->toBe('ada')
        ->and($anonymousLogout->authentication)->toBeNull()
        ->and($denial->authentication->getName())->toBe('ada')
        ->and($denial->subject)->toBe('App\\Reports::totals')
        ->and($denial->expression)->toBe("hasRole('ADMIN')");
});
