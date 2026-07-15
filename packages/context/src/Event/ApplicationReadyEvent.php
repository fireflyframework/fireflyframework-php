<?php

declare(strict_types=1);

namespace Firefly\Context\Event;

use DateTimeImmutable;

/**
 * Published once the application is ready to serve — Spring's ApplicationReadyEvent, ported.
 *
 * FLAT `final readonly`, NO common base class with ContextRefreshedEvent/ContextClosedEvent — this
 * is deliberate, not an oversight. Illuminate\Events\Dispatcher::getListeners() matches listeners on
 * the event's CONCRETE class name (plus class_implements(), for interfaces) — it never walks
 * class_parents(). An `ApplicationEvent` base "for tidiness" would create a listener-matching trap:
 * a listener registered against the base class would silently never fire for any of these, because
 * Illuminate never looks there. Keeping the three lifecycle events flat and unrelated makes that bug
 * structurally impossible instead of merely documented against.
 */
final readonly class ApplicationReadyEvent
{
    public function __construct(public DateTimeImmutable $readyAt) {}
}
