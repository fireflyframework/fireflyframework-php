<?php

declare(strict_types=1);

namespace Firefly\Security\Event;

use Firefly\Context\Event\ApplicationEventPublisher;
use Firefly\Kernel\Exception\Security\AuthenticationException;
use Firefly\Security\Authentication\Exception\DisabledException;
use Firefly\Security\Authentication\Exception\LockedException;
use Firefly\Security\Core\Authentication;

/**
 * The one place a security outcome becomes an application event (Spring's DefaultAuthenticationEventPublisher).
 * The filters call these five methods and never construct an event themselves, so the mapping from an
 * exception to its event — and the rule that an unknown user is a BAD CREDENTIALS event — lives here once.
 * Publishing goes through the context ApplicationEventPublisher port, which is what makes the events reach
 * #[AsEventListener] methods and what makes Event::fake() see them in a test.
 */
final class AuthenticationEventPublisher
{
    public function __construct(private readonly ApplicationEventPublisher $events) {}

    public function publishAuthenticationSuccess(Authentication $authentication): void
    {
        $this->events->publish(new AuthenticationSuccessEvent($authentication));
    }

    public function publishInteractiveSuccess(Authentication $authentication, string $mechanism): void
    {
        $this->publishAuthenticationSuccess($authentication);
        $this->events->publish(new InteractiveAuthenticationSuccessEvent($authentication, $mechanism));
    }

    public function publishAuthenticationFailure(AuthenticationException $exception, string $username, string $ip): void
    {
        $this->events->publish(match (true) {
            $exception instanceof LockedException => new AuthenticationFailureLockedEvent($username, $ip, $exception),
            $exception instanceof DisabledException => new AuthenticationFailureDisabledEvent($username, $ip, $exception),
            default => new AuthenticationFailureBadCredentialsEvent($username, $ip, $exception),
        });
    }

    public function publishLogoutSuccess(?Authentication $authentication): void
    {
        $this->events->publish(new LogoutSuccessEvent($authentication));
    }

    public function publishAuthorizationDenied(Authentication $authentication, string $subject, ?string $expression): void
    {
        $this->events->publish(new AuthorizationDeniedEvent($authentication, $subject, $expression));
    }
}
