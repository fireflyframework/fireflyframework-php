<?php

declare(strict_types=1);

namespace Firefly\Testing\Double;

use Firefly\Context\Event\ApplicationEventPublisher;
use Firefly\Security\Event\AbstractAuthenticationFailureEvent;
use Firefly\Security\Event\AuthenticationSuccessEvent;
use Firefly\Security\Event\AuthorizationDeniedEvent;
use Firefly\Security\Event\InteractiveAuthenticationSuccessEvent;
use Firefly\Security\Event\LogoutSuccessEvent;

/**
 * A spy over the context ApplicationEventPublisher port that knows the security event family: it records
 * every published object in order (like RecordingApplicationEventPublisher) and answers the five security
 * kinds by name, so a flow test reads `$events->failures()[0]->username` instead of filtering by class.
 *
 * Bind it BEFORE boot — `$app->instance(ApplicationEventPublisher::class, $events)` from
 * defineFireflyEnvironment() — so firefly/security's AuthenticationEventPublisher bean wraps it; the
 * framework's own default is a bound()-guarded provider binding, which is why an instance() wins there.
 *
 * Bound that way it REPLACES the port, so nothing registered against the dispatcher hears a security event any
 * more — an #[AsEventListener] on InteractiveAuthenticationSuccessEvent stays silent for the whole suite. A suite
 * whose pipeline includes such a listener (firefly/security-oauth2-server stamps the sign-in instant that way)
 * hands the framework's own publisher in as $forwardTo — `new RecordingAuthenticationEvents(new
 * DispatcherEventPublisher($app))` — and every event is recorded first and then published for real; the
 * default, null, keeps the spy a pure recorder for a suite that only reads.
 */
final class RecordingAuthenticationEvents implements ApplicationEventPublisher
{
    /** @var list<object> */
    public array $events = [];

    public function __construct(private readonly ?ApplicationEventPublisher $forwardTo = null) {}

    public function publish(object $event): void
    {
        $this->events[] = $event;
        $this->forwardTo?->publish($event);
    }

    /** @return list<AuthenticationSuccessEvent> */
    public function successes(): array
    {
        /** @var list<AuthenticationSuccessEvent> */
        return $this->ofType(AuthenticationSuccessEvent::class);
    }

    /** @return list<InteractiveAuthenticationSuccessEvent> */
    public function interactive(): array
    {
        /** @var list<InteractiveAuthenticationSuccessEvent> */
        return $this->ofType(InteractiveAuthenticationSuccessEvent::class);
    }

    /** @return list<AbstractAuthenticationFailureEvent> */
    public function failures(): array
    {
        /** @var list<AbstractAuthenticationFailureEvent> */
        return $this->ofType(AbstractAuthenticationFailureEvent::class);
    }

    /** @return list<LogoutSuccessEvent> */
    public function logouts(): array
    {
        /** @var list<LogoutSuccessEvent> */
        return $this->ofType(LogoutSuccessEvent::class);
    }

    /** @return list<AuthorizationDeniedEvent> */
    public function denials(): array
    {
        /** @var list<AuthorizationDeniedEvent> */
        return $this->ofType(AuthorizationDeniedEvent::class);
    }

    /**
     * @param  class-string  $class
     * @return list<object>
     */
    public function ofType(string $class): array
    {
        return array_values(array_filter($this->events, static fn (object $e): bool => $e instanceof $class));
    }

    public function reset(): void
    {
        $this->events = [];
    }
}
