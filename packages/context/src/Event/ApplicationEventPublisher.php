<?php

declare(strict_types=1);

namespace Firefly\Context\Event;

/**
 * The hexagonal port application code programs to: Spring's ApplicationEventPublisher, ported.
 *
 * Application code depends ONLY on this interface, never on Illuminate's
 * \Illuminate\Contracts\Events\Dispatcher — DispatcherEventPublisher is the one adapter that knows
 * illuminate/events does the actual work. See DispatcherEventPublisher's docblock for why the
 * adapter resolves that dependency fresh on every call instead of taking it as a constructor
 * dependency.
 */
interface ApplicationEventPublisher
{
    public function publish(object $event): void;
}
