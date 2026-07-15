<?php

declare(strict_types=1);

namespace Firefly\Context\Event;

use Attribute;

/**
 * Marks a method as an application event listener — Spring's @EventListener, ported.
 *
 * INERT METADATA ONLY, same rule as every other Firefly attribute (see e.g.
 * Firefly\Context\Lifecycle\PostConstruct): this class carries no discovery/dispatch/registration
 * logic. A later boot pass (RegisterEventListenersPass, not built in this milestone slice) reflects
 * this attribute and wires the annotated method against Illuminate's dispatcher, routed through
 * DispatcherEventPublisher::guardListener() for the notification-not-filter guarantee.
 *
 * $event is the fully-qualified event class name to listen for. null (the default) means INFER it
 * from the listener method's first parameter type, mirroring Spring's @EventListener, which only
 * requires an explicit type when it cannot be read off the method signature.
 *
 * $order follows the #[Order] convention used throughout Firefly (Firefly\Container\Attributes\Order):
 * LOWER runs first, default 0.
 *
 * IS_REPEATABLE: a single method may listen for more than one event.
 */
#[Attribute(Attribute::TARGET_METHOD | Attribute::IS_REPEATABLE)]
final readonly class AsEventListener
{
    public function __construct(
        public ?string $event = null,
        public int $order = 0,
    ) {}
}
